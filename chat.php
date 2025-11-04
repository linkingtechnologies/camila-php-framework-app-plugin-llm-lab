<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Local Chat – llama.cpp</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bulma@1.0.2/css/bulma.min.css">
  <style>
    html, body { height: 100%; }
    .chat-container { height: calc(100vh - 260px); overflow-y: auto; }
    .message-bubble { white-space: pre-wrap; }
    .mono { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace; }
    .footer-note { font-size: .85rem; opacity: .8; }
    .hidden { display:none !important; }
  </style>
</head>
<body>
  <section class="section py-4">
    <div class="container">
      <h1 class="title is-4">Local Chat with <span class="mono">llama.cpp</span></h1>
      <!--<p class="subtitle is-6">OpenAI-compatible <span class="mono">llama.cpp</span> server.</p>-->

      <!-- Tabs -->
      <div class="tabs is-boxed">
        <ul>
          <li class="is-active" data-tab="chat"><a>Chat</a></li>
          <li data-tab="settings"><a>Settings</a></li>
        </ul>
      </div>

      <div id="tab-chat">
        <div class="box" style="height: 70vh; display:flex; flex-direction: column;">
          <div id="chat" class="chat-container"></div>

          <div id="statusRow" class="mt-3 hidden">
            <progress class="progress is-small is-info" max="100">Loading</progress>
            <p class="help">Waiting for response...</p>
          </div>

          <label class="label mt-3">Message</label>
          <div class="field has-addons">
            <div class="control is-expanded">
              <textarea id="prompt" class="textarea" rows="3" placeholder="Type and press Enter..."></textarea>
            </div>
            <div class="control">
              <button id="sendBtn" class="button is-primary">Send</button>
            </div>
          </div>

          <div class="is-flex is-justify-content-space-between">
            <button id="clearBtn" class="button is-light">Clear chat</button>
            <label class="checkbox">
              <input id="rememberToggle" type="checkbox" checked> Remember settings
            </label>
          </div>
        </div>
      </div>

      <div id="tab-settings" class="hidden">
        <div class="box">
          <h2 class="title is-6 mb-3">Settings</h2>

          <div class="field">
            <label class="label">Endpoint</label>
            <input id="endpoint" class="input mono" type="text" value="http://localhost/app/llmlab/plugins/llm-lab/chat-proxy.php" />
            <p class="help">llama.cpp server URL (OpenAI-compatible)</p>
          </div>

          <div class="field">
            <label class="label">Model</label>
            <input id="model" class="input mono" type="text" value="local-model" />
          </div>

          <div class="field">
            <label class="label">System prompt (optional)</label>
            <textarea id="system" class="textarea" rows="3" placeholder="Instructions for the assistant..."></textarea>
          </div>

          <div class="field is-horizontal">
            <div class="field-body">
              <div class="field">
                <label class="label">Temperature</label>
                <input id="temperature" class="input" type="number" step="0.1" min="0" max="2" value="0.7" />
              </div>
              <div class="field">
                <label class="label">Max tokens</label>
                <input id="maxTokens" class="input" type="number" step="1" min="1" value="512" />
              </div>
              <div class="field">
                <label class="checkbox mt-5">
                  <input id="streamToggle" type="checkbox" checked> Streaming
                </label>
              </div>
            </div>
          </div>

          <p class="footer-note">If blocked by CORS, start the server with <span class="mono">--api-key any --cors</span>.</p>
        </div>
      </div>

    </div>
  </section>

  <script>
    // --- Tabs logic ---
    const tabEls = document.querySelectorAll('.tabs li');
    const tabPanels = { chat: document.getElementById('tab-chat'), settings: document.getElementById('tab-settings') };
    tabEls.forEach(li=>li.addEventListener('click',()=>{
      tabEls.forEach(x=>x.classList.remove('is-active'));
      li.classList.add('is-active');
      const name = li.getAttribute('data-tab');
      Object.entries(tabPanels).forEach(([k,el])=> el.classList.toggle('hidden', k!==name));
    }));

    // --- State ---
    const els = {
      endpoint: document.getElementById('endpoint'),
      model: document.getElementById('model'),
      system: document.getElementById('system'),
      temperature: document.getElementById('temperature'),
      maxTokens: document.getElementById('maxTokens'),
      streamToggle: document.getElementById('streamToggle'),
      rememberToggle: document.getElementById('rememberToggle'),
      chat: document.getElementById('chat'),
      prompt: document.getElementById('prompt'),
      sendBtn: document.getElementById('sendBtn'),
      clearBtn: document.getElementById('clearBtn'),
      statusRow: document.getElementById('statusRow'),
    };

    let messages = [];
    let isBusy = false;

    // --- Persistence ---
    function loadMemory() {
      try { messages = JSON.parse(localStorage.getItem('llamacpp-memory') || '[]'); } catch {}
      messages.forEach(m => addBubble(m.role, m.content));
    }
    function saveMemory() { localStorage.setItem('llamacpp-memory', JSON.stringify(messages)); }

    function loadSettings() {
      try {
        const s = JSON.parse(localStorage.getItem('llamacpp-ui-settings')||'{}');
        if (s.endpoint) els.endpoint.value = s.endpoint;
        if (s.model) els.model.value = s.model;
        if (s.system) els.system.value = s.system;
        if (typeof s.temperature==='number') els.temperature.value=s.temperature;
        if (typeof s.maxTokens==='number') els.maxTokens.value=s.maxTokens;
        if (typeof s.streaming==='boolean') els.streamToggle.checked=s.streaming;
      } catch {}
    }
    function saveSettings() {
      if (!els.rememberToggle.checked) return;
      localStorage.setItem('llamacpp-ui-settings', JSON.stringify({
        endpoint: els.endpoint.value.trim(),
        model: els.model.value.trim(),
        system: els.system.value,
        temperature: Number(els.temperature.value),
        max_tokens: Number(els.maxTokens.value),
        streaming: !!els.streamToggle.checked,
      }));
    }

    loadSettings();
    loadMemory();

    // --- UI helpers ---
    function scrollToBottom(){ els.chat.scrollTop = els.chat.scrollHeight; }
    function addBubble(role,text){
      const a=document.createElement('article');
      a.className='message '+(role==='user'?'is-success':'is-info');
      a.innerHTML=`<div class="message-header"><span>${role==='user'?'You':'Assistant'}</span></div>`;
      const b=document.createElement('div'); b.className='message-body message-bubble'; b.textContent=text;
      a.appendChild(b); els.chat.appendChild(a); scrollToBottom(); return b;
    }
    function setBubbleText(el,t){ el.textContent=t; scrollToBottom(); }

    function setBusy(v){
      isBusy = !!v;
      els.sendBtn.disabled = isBusy;
      els.prompt.disabled = isBusy;
      els.sendBtn.classList.toggle('is-loading', isBusy);
      els.statusRow.classList.toggle('hidden', !isBusy);
    }

    // --- Chat ---
    async function sendMessage(){
      const userText=els.prompt.value.trim(); if(!userText || isBusy) return;
      setBusy(true);
      saveSettings(); els.prompt.value='';
      messages.push({role:'user',content:userText}); saveMemory(); addBubble('user',userText);

      const payload={
        model:els.model.value.trim()||'local-model',
        messages:[...(els.system.value.trim()?[{role:'system',content:els.system.value.trim()}]:[]),...messages],
        temperature:Number(els.temperature.value)||0.7,
        max_tokens:Number(els.maxTokens.value)||512,
        stream:!!els.streamToggle.checked,
      };

      const ep=els.endpoint.value.trim(); const bub=addBubble('assistant','');

      try{
        const r=await fetch(ep,{ method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify(payload) });
        if(!r.ok){ throw new Error(`HTTP ${r.status} – ${await r.text().catch(()=>'' )}`); }
        const ct=(r.headers.get('content-type')||'').toLowerCase();
        if(ct.includes('text/event-stream')){ await handleStream(r,bub); }
        else{ const d=await r.json(); const t=d.choices?.[0]?.message?.content||''; setBubbleText(bub,t); messages.push({role:'assistant',content:t}); saveMemory(); }
      }catch(e){ setBubbleText(bub,'Error: '+e.message); }
      finally { setBusy(false); }
    }

    async function handleStream(r,bub){
      const rd=r.body.getReader(),dec=new TextDecoder(); let acc='',full='';
      while(true){ const {value,done}=await rd.read(); if(done) break; acc+=dec.decode(value,{stream:true});
        const lines=acc.split(/\n/); acc=lines.pop();
        for(const l of lines){ const t=l.trim(); if(!t) continue; if(t==='data: [DONE]') break;
          if(t.startsWith('data: ')){
            try{ const j=JSON.parse(t.slice(6)); const d=j.choices?.[0]?.delta?.content||''; if(d){ full+=d; setBubbleText(bub,full);} }catch{}
          }
        }
      }
      messages.push({role:'assistant',content:full}); saveMemory();
    }

    // --- Events ---
    els.sendBtn.onclick=sendMessage;
    els.prompt.onkeydown=e=>{ if(e.key==='Enter'&&!e.shiftKey){e.preventDefault();sendMessage();}};
    els.clearBtn.onclick=()=>{ messages=[]; els.chat.innerHTML=''; localStorage.removeItem('llamacpp-memory'); };
  </script>
</body>
</html>
