<?php
/**
 * Unified chat proxy for OpenAI/llama.cpp with MCP interception + real SSE passthrough.
 *
 * Modes:
 *  - stream=false: JSON response (OpenAI-style).
 *  - stream=true:
 *      - If NO MCP is requested by the model: raw SSE passthrough (token-by-token).
 *      - If MCP IS requested: orchestrate in non-stream, then send ONE SSE chunk + [DONE].
 *
 * Env flags:
 *  - AI_PROVIDER: 'OPENAI' | 'LLAMA' (default: LLAMA)
 *  - OPENAI_API_KEY: key for OpenAI (if using OPENAI)
 *  - LLAMA_URL: llama.cpp OpenAI-compatible endpoint (default: http://127.0.0.1:8181/v1/chat/completions)
 *  - LLAMA_BEARER: e.g. 'any' if llama-server was started with --api-key any
 *  - INTERCEPT_MCP=1|0 (default 1): enable MCP orchestration
 *  - MCP_BRIDGE_URL (default http://127.0.0.1:8765/mcp/call): HTTP bridge to MCP servers
 *  - RAW_STREAM_WHEN_NO_MCP=1|0 (default 1): allow raw SSE passthrough if no MCP occurs
 */

declare(strict_types=1);

// ------------ CONFIG ------------
$TARGET = getenv('AI_PROVIDER') ?: 'LLAMA'; // 'OPENAI' | 'LLAMA'

$OPENAI_KEY = getenv('OPENAI_API_KEY') ?: '';
$OPENAI_URL = 'https://api.openai.com/v1/chat/completions';

$LLAMA_URL    = getenv('LLAMA_URL')    ?: 'http://127.0.0.1:8181/v1/chat/completions';
$LLAMA_BEARER = getenv('LLAMA_BEARER') ?: ''; // e.g. 'any' if llama-server --api-key any

$INTERCEPT_MCP          = (getenv('INTERCEPT_MCP') ?: '1') === '1';
$MCP_BRIDGE_URL         = getenv('MCP_BRIDGE_URL') ?: 'http://127.0.0.1:8765/mcp/call';
$RAW_STREAM_WHEN_NO_MCP = (getenv('RAW_STREAM_WHEN_NO_MCP') ?: '1') === '1';

// ------------ CORS ------------
header('Access-Control-Allow-Origin: https://your-domain.example'); // or '*' for local
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

// ------------ Helpers (buffering/SSE hygiene) ------------
function sse_headers(): void {
  header('Content-Type: text/event-stream');
  header('Cache-Control: no-cache, no-transform');
  header('Connection: keep-alive');
  header('X-Accel-Buffering: no'); // nginx hint (harmless elsewhere)

  @ini_set('output_buffering', 'off');
  @ini_set('zlib.output_compression', '0');

  // Flush and close all output buffers
  while (ob_get_level() > 0) { @ob_end_flush(); }

  // Enable implicit flush (PHP 8+ requires a bool)
  if (function_exists('ob_implicit_flush')) {
    ob_implicit_flush(true);
  }

  // Small padding to nudge some clients/proxies on Windows
  echo ": stream start\n\n";
  @flush();
  usleep(10000); // 10ms
  echo ":\n\n";
  @flush();
}

function http_json(string $url, array $body, array $headers = [], int $timeout = 0): array {
  $ch = curl_init();
  curl_setopt_array($ch, [
    CURLOPT_URL => $url,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => array_values(array_filter(array_merge(['Content-Type: application/json'], $headers))),
    CURLOPT_POSTFIELDS => json_encode($body),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER => false,
    CURLOPT_TIMEOUT => $timeout, // 0 = unlimited
    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1, // keep 1.1 for consistency
  ]);
  $resp = curl_exec($ch);
  if ($resp === false) { $err = curl_error($ch); curl_close($ch); return [null, 0, $err]; }
  $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
  curl_close($ch);
  return [$resp, $code, null];
}

function chat_non_stream(string $target, string $openaiKey, string $openaiUrl, string $llamaUrl, string $llamaBearer, array $payload): array {
  $url = ($target === 'OPENAI') ? $openaiUrl : $llamaUrl;
  $headers = [];
  if ($target === 'OPENAI' && $openaiKey) $headers[] = 'Authorization: Bearer '.$openaiKey;
  if ($target === 'LLAMA'  && $llamaBearer) $headers[] = 'Authorization: Bearer '.$llamaBearer;
  $p = $payload; $p['stream'] = false;
  return http_json($url, $p, $headers, 0);
}

function try_extract_mcp_request(string $text): ?array {
  // Pattern: line starting with "TOOL: { ... }"
  $pattern = '/^\\s*TOOL:\\s*(\\{.*\\})\\s*$/mi';
  if (preg_match($pattern, $text, $m)) {
    $json = json_decode($m[1], true);
    if (is_array($json) && isset($json['name'])) {
      return ['name' => $json['name'], 'args' => (isset($json['args']) && is_array($json['args'])) ? $json['args'] : []];
    }
  }
  return null;
}

function call_mcp_bridge(string $bridgeUrl, string $name, array $args): string {
  [$resp, $code, $err] = http_json($bridgeUrl, ['name' => $name, 'args' => $args], [], 0);
  if ($resp === null) return "[MCP ERROR] $err";
  $data = json_decode($resp, true);
  if (!is_array($data)) return "[MCP ERROR] Invalid JSON from bridge";
  $result = $data['result'] ?? $data;
  return is_string($result) ? $result : json_encode($result, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}

function make_chat_response(string $content, string $model = 'local-model'): array {
  return [
    'id' => 'chatcmpl_' . bin2hex(random_bytes(6)),
    'object' => 'chat.completion',
    'created' => time(),
    'model' => $model,
    'choices' => [[
      'index' => 0,
      'message' => ['role' => 'assistant', 'content' => $content],
      'finish_reason' => 'stop',
    ]],
    'usage' => ['prompt_tokens' => null, 'completion_tokens' => null, 'total_tokens' => null],
  ];
}

function sse_send_final_chunk(string $text, string $model='local-model'): void {
  sse_headers();
  $chunk = [
    'id' => 'chatcmpl_' . bin2hex(random_bytes(4)),
    'object' => 'chat.completion.chunk',
    'created' => time(),
    'model' => $model,
    'choices' => [[ 'index' => 0, 'delta' => [ 'content' => $text ] ]]
  ];
  echo 'data: ' . json_encode($chunk, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) . "\n\n";
  echo "data: [DONE]\n\n";
  flush();
}

// ------------ Input ------------
set_time_limit(0);
ignore_user_abort(true);
if (function_exists('session_status') && session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }

$raw = file_get_contents('php://input');
$payload = json_decode($raw, true);
if (!is_array($payload)) {
  http_response_code(400);
  header('Content-Type: application/json');
  echo json_encode(['error' => ['message' => 'Invalid JSON body']]);
  exit;
}
$payload += ['stream' => false];
$payload['temperature'] = $payload['temperature'] ?? 0.7;
$payload['max_tokens']  = $payload['max_tokens']  ?? 512;

$model  = $payload['model'] ?? 'local-model';
$targetUrl = ($TARGET === 'OPENAI') ? $OPENAI_URL : $LLAMA_URL;

$authHeaders = [];
if ($TARGET === 'OPENAI' && $OPENAI_KEY) $authHeaders[] = 'Authorization: Bearer '.$OPENAI_KEY;
if ($TARGET === 'LLAMA'  && $LLAMA_BEARER) $authHeaders[] = 'Authorization: Bearer '.$LLAMA_BEARER;

// ------------ Route by mode ------------

// Case 1: MCP disabled AND client asked for stream → raw SSE passthrough (pure)
if (!$INTERCEPT_MCP && !empty($payload['stream'])) {
  sse_headers();

  $ch = curl_init();
  curl_setopt_array($ch, [
    CURLOPT_URL => $targetUrl,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => array_values(array_filter(array_merge([
      'Content-Type: application/json',
      'Accept: text/event-stream',
      'Expect:' // disable 100-continue
    ], $authHeaders))),
    CURLOPT_POSTFIELDS => json_encode($payload + ['stream' => true]),
    CURLOPT_RETURNTRANSFER => false,           // stream!
    CURLOPT_HEADER => false,
    CURLOPT_TIMEOUT => 0,
    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1, // force HTTP/1.1
    CURLOPT_WRITEFUNCTION => function($ch, $chunk) {
      echo $chunk; flush();
      return strlen($chunk);
    }
  ]);
  $ok = curl_exec($ch);
  if ($ok === false) echo "data: ".json_encode(['error'=>['message'=>curl_error($ch)]]) . "\n\n";
  curl_close($ch);
  echo "data: [DONE]\n\n"; flush();
  exit;
}

// Case 2: stream requested and we want real passthrough if NO MCP occurs
if (!empty($payload['stream']) && $RAW_STREAM_WHEN_NO_MCP) {
  // First hop: non-stream to check if MCP is requested
  $checkPayload = $payload; $checkPayload['stream'] = false;
  [$resp, $code, $err] = chat_non_stream($TARGET, $OPENAI_KEY, $OPENAI_URL, $LLAMA_URL, $LLAMA_BEARER, $checkPayload);
  if ($resp === null) {
    sse_send_final_chunk("[ERROR] ".($err ?: 'Upstream error ('.$code.')'), $model); exit;
  }
  $data = json_decode($resp, true);
  $assistant = $data['choices'][0]['message']['content'] ?? '';

  if ($INTERCEPT_MCP) {
    $mcp = try_extract_mcp_request($assistant);
    if ($mcp) {
      // Orchestrate in non-stream, then return single SSE chunk
      $messages = $payload['messages'] ?? [];
      $messages[] = ['role' => 'assistant', 'content' => $assistant];

      $max_hops = 4;
      $final = $assistant;

      for ($hop=0; $hop<$max_hops; $hop++) {
        $req = try_extract_mcp_request($final);
        if (!$req) break;
        $toolResult = call_mcp_bridge($MCP_BRIDGE_URL, $req['name'], $req['args']);
        $messages[] = ['role'=>'tool','name'=>$req['name'],'content'=>$toolResult];
        $messages[] = ['role'=>'system','content'=>"Tool '{$req['name']}' returned the result above. Continue the answer using that information."];

        $tmp = $payload; $tmp['messages'] = $messages; $tmp['stream'] = false;
        [$resp2, $code2, $err2] = chat_non_stream($TARGET,$OPENAI_KEY,$OPENAI_URL,$LLAMA_URL,$LLAMA_BEARER,$tmp);
        if ($resp2 === null) { $final = "[ERROR] ".($err2 ?: 'Upstream error ('.$code2.')'); break; }
        $d2 = json_decode($resp2, true);
        $final = $d2['choices'][0]['message']['content'] ?? '';
        $messages[] = ['role'=>'assistant','content'=>$final];
      }
      sse_send_final_chunk($final ?: '[MCP loop finished]', $model); exit;
    }
  }

  // NO MCP → raw SSE passthrough with the ORIGINAL payload
  sse_headers();
  $ch = curl_init();
  curl_setopt_array($ch, [
    CURLOPT_URL => $targetUrl,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => array_values(array_filter(array_merge([
      'Content-Type: application/json',
      'Accept: text/event-stream',
      'Expect:' // disable 100-continue
    ], $authHeaders))),
    CURLOPT_POSTFIELDS => json_encode($payload + ['stream' => true]),
    CURLOPT_RETURNTRANSFER => false,           // stream!
    CURLOPT_HEADER => false,
    CURLOPT_TIMEOUT => 0,
    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1, // force HTTP/1.1
    CURLOPT_WRITEFUNCTION => function($ch, $chunk) {
      echo $chunk; flush();
      return strlen($chunk);
    }
  ]);
  $ok = curl_exec($ch);
  if ($ok === false) echo "data: ".json_encode(['error'=>['message'=>curl_error($ch)]]) . "\n\n";
  curl_close($ch);
  echo "data: [DONE]\n\n"; flush();
  exit;
}

// Case 3: stream=false OR RAW_STREAM_WHEN_NO_MCP disabled → full non-stream orchestration
$messages = $payload['messages'] ?? [];
$max_hops = 4;
$lastAssistant = '';

for ($hop=0; $hop<$max_hops; $hop++) {
  $tmp = $payload; $tmp['messages'] = $messages; $tmp['stream'] = false;
  [$dataResp, $code, $err] = chat_non_stream($TARGET,$OPENAI_KEY,$OPENAI_URL,$LLAMA_URL,$LLAMA_BEARER,$tmp);
  if ($dataResp === null) {
    if (!empty($payload['stream'])) { sse_send_final_chunk("[ERROR] ".($err ?: 'Upstream error ('.$code.')'), $model); }
    else {
      http_response_code(502); header('Content-Type: application/json');
      echo json_encode(['error'=>['message'=>$err ?: 'Upstream error']]);
    }
    exit;
  }
  $data = json_decode($dataResp, true);
  $assistant = $data['choices'][0]['message']['content'] ?? '';
  $lastAssistant = $assistant;

  if (!$INTERCEPT_MCP) break;
  $mcp = try_extract_mcp_request($assistant);
  if (!$mcp) break;

  $toolResult = call_mcp_bridge($MCP_BRIDGE_URL, $mcp['name'], $mcp['args']);
  $messages[] = ['role'=>'assistant','content'=>$assistant];
  $messages[] = ['role'=>'tool','name'=>$mcp['name'],'content'=>$toolResult];
  $messages[] = ['role'=>'system','content'=>"Tool '{$mcp['name']}' returned the result above. Continue the answer using that information."];
}

// Final response to client
if (!empty($payload['stream'])) {
  sse_send_final_chunk($lastAssistant ?: '[MCP loop limit reached]', $model);
} else {
  http_response_code(200);
  header('Content-Type: application/json');
  echo json_encode(make_chat_response($lastAssistant ?: '[MCP loop limit reached]', $model));
}
