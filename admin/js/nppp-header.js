/**
 * JavaScript for Aurora Canvas Header Effect
 * Description: Aurora ribbons that react to plugin actions and preload progress (status, % complete, errors).
 * Version: 2.1.3
 * Author: Hasan CALISIR
 * Author Email: hasan.calisir@psauxit.com
 * Author URI: https://www.psauxit.com
 * License: GPL-2.0+
 */

(function(){
  'use strict';

  // -------------------------------
  // Config (safe to tweak)
  // -------------------------------
  const CFG = {
    selector: '.wrap .nppp-header-content, .nppp-header-content',
    hueStops: [330, 270, 210, 190, 165, 140, 95, 45],
    sat: 96,
    light: 60,
    alpha: 0.18,
    ribbons: 4,
    thickness: 0.9,
    trail: 0.075,
    speed: 0.20,
    noiseScale: 0.0018,
    pulseEvery: [4, 6],
    ajaxPulseAmp: 1.25,
    ajaxPulseHueBias: 25,

    // Progress coupling
    progressEndpoint: null,              // will auto-fill from window.nppp_admin_data.wget_progress_api if present
    progressEndpointMatch: null,         // optional RegExp string to match endpoint
    reactToAjax: true,                   // keep generic network pulses
    reactToProgressResponse: true,       // parse progress JSON and map to visuals
    reducedMotionFallbackAlpha: 0.10,    // lower glow when reduced motion is requested
  };

  // Modes for semantic states
  const MODE = {
    IDLE: 'idle',
    RUNNING: 'running',
    DONE: 'done',
    ALERT: 'alert'
  };

  // -------------------------------
  // Utilities
  // -------------------------------
  const clamp = (v, lo, hi) => Math.max(lo, Math.min(hi, v));
  const lerp = (a, b, t) => a + (b - a) * t;

  function safeGetProgressEndpoint(){
    try{
      if (CFG.progressEndpoint) return CFG.progressEndpoint;
      if (window.nppp_admin_data && window.nppp_admin_data.wget_progress_api){
        return String(window.nppp_admin_data.wget_progress_api);
      }
    }catch(e){}
    return null;
  }

  function hslaStr(h,s,l,a){ return `hsla(${h},${s}%,${l}%,${a})`; }

  // Small, fast 2D simplex noise (Stefan Gustavson, trimmed)
  const G2 = (3.0 - Math.sqrt(3.0))/6.0;
  const F2 = 0.5*(Math.sqrt(3.0)-1.0);
  const perm = new Uint8Array(512);
  (function buildPerm(){
    const p = new Uint8Array(256);
    for(let i=0;i<256;i++) p[i]=i;
    for(let i=255;i>0;i--){
      const n=(Math.random()*(i+1))|0;
      const q=p[i]; p[i]=p[n]; p[n]=q;
    }
    for(let i=0;i<512;i++) perm[i]=p[i&255];
  })();
  function noise2D(xin,yin){
    const s = (xin+yin)*F2;
    const i = Math.floor(xin+s);
    const j = Math.floor(yin+s);
    const t = (i+j)*G2;
    const X0 = i - t, Y0 = j - t;
    const x0 = xin - X0, y0 = yin - Y0;
    const i1 = x0>y0 ? 1 : 0;
    const j1 = x0>y0 ? 0 : 1;
    const x1 = x0 - i1 + G2, y1 = y0 - j1 + G2;
    const x2 = x0 - 1 + 2*G2, y2 = y0 - 1 + 2*G2;
    const ii = i & 255, jj = j & 255;

    function gi(a,b){
      const g = perm[a+perm[b]] % 12;
      const gx = [1,-1,1,-1, 1,-1, 0, 0, 1,-1, 1,-1][g];
      const gy = [0, 0,1, 1,-1,-1, 1,-1, 1, 1,-1,-1][g];
      return [gx, gy];
    }
    function dot(gx,gy,x,y){ return gx*x + gy*y; }

    let n0=0,n1=0,n2=0;

    let t0 = 0.5 - x0*x0 - y0*y0;
    if (t0>=0){ const g=gi(ii,jj); t0*=t0; n0 = t0*t0*dot(g[0],g[1],x0,y0); }

    let t1 = 0.5 - x1*x1 - y1*y1;
    if (t1>=0){ const g=gi(ii+i1,jj+j1); t1*=t1; n1 = t1*t1*dot(g[0],g[1],x1,y1); }

    let t2 = 0.5 - x2*x2 - y2*y2;
    if (t2>=0){ const g=gi(ii+1,jj+1); t2*=t2; n2 = t2*t2*dot(g[0],g[1],x2,y2); }

    return 70*(n0+n1+n2);
  }

  // -------------------------------
  // Aurora engine
  // -------------------------------
  const state = {
    host: null,
    canvas: null,
    ctx: null,
    DPR: 1,
    W: 0,
    H: 0,
    ribbons: [],
    then: 0,
    reduce: false,
    nextPulse: 0,
    mode: MODE.IDLE,
    pct: 0,          // 0..100
    lastPulseAt: 0,
    disposed: false,
    _dprTimer: null,
    inView: true,
    _io: null,
    _ro: null
  };

  function pickHost(){
    const host = document.querySelector(CFG.selector);
    if (!host) return null;
    const style = getComputedStyle(host);
    if (style.position === 'static') host.style.position = 'relative';
    if (style.zIndex === 'auto') host.style.zIndex = 0;
    return host;
  }

  function ensureCanvas(){
    let canvas = state.host.querySelector('.nppp-aurora-canvas');
    if (!canvas){
      canvas = document.createElement('canvas');
      canvas.className = 'nppp-aurora-canvas';
      canvas.style.cssText = 'position:absolute;inset:0;pointer-events:none;display:block;z-index:-1;';
      if (state.host.firstChild) {
        state.host.insertBefore(canvas, state.host.firstChild);
      } else {
        state.host.appendChild(canvas);
      }
    }
    return canvas;
  }

  function liveRect(){
    const r = state.host.getBoundingClientRect();
    const tail = Math.min(Math.max(160, r.width*0.25), 320);
    return { x: -tail*0.25, y: 0, w: r.width + tail, h: r.height, tail };
  }

  function resetRibbons(){
    const L = liveRect();
    state.ribbons.length = 0;
    for(let i=0;i<CFG.ribbons;i++){
      const y = L.y + (L.h * (0.2 + 0.6*Math.random()));
      const hue = CFG.hueStops[i % CFG.hueStops.length];
      state.ribbons.push({
        y, baseY: y,
        x: L.x + Math.random()*L.w,
        hue,
        amp: 1,
        seed: Math.random()*10000
      });
    }
  }

  function resize(){
    if (!state.host || !state.canvas) return;
    const r = state.host.getBoundingClientRect();
    const w = Math.max(320, r.width|0);
    const h = Math.max(80,  r.height|0);
    state.W = w; state.H = h;

    const wPx = (w*state.DPR)|0, hPx=(h*state.DPR)|0;
    if (state.canvas.width!==wPx) state.canvas.width=wPx;
    if (state.canvas.height!==hPx) state.canvas.height=hPx;
    state.canvas.style.width = w+'px';
    state.canvas.style.height = h+'px';
    state.ctx.setTransform(state.DPR,0,0,state.DPR,0,0);
    resetRibbons();
  }

  function schedulePulse(nowS){
    const [a,b] = CFG.pulseEvery;
    state.nextPulse = nowS + (a + Math.random()*(b-a));
  }

  function pulse(amp=1, hueBias=0){
    const t = performance.now()/1000;
    state.lastPulseAt = t;
    for(const r of state.ribbons){
      r.amp = Math.min(2.2, r.amp * (1 + 0.25*amp));
      r.hue = (r.hue + hueBias + 360) % 360;
    }
  }

  // Map progress/mode to continuous parameters (speed, alpha, hue drift)
  function applyProgressShaping(){
    const pct01 = clamp(state.pct/100, 0, 1);

    // speed ramps up gently while running, calm otherwise
    const targetSpeed = (state.mode === MODE.RUNNING)
      ? lerp(0.18, 0.35, Math.pow(pct01, 0.75))
      : (state.mode === MODE.DONE ? 0.12 : 0.20);

    CFG.speed = targetSpeed;

    // alpha glow stronger in running, subtle when done/idle
    const baseAlpha = (state.mode === MODE.RUNNING) ? 0.22 : (state.mode===MODE.DONE ? 0.14 : 0.18);
    CFG.alpha = state.reduce ? CFG.reducedMotionFallbackAlpha : baseAlpha;

    // global hue drift toward “completion blue” as pct→1
    const completionHueBias = lerp(+18, -80, Math.pow(pct01, 0.9)); // shifts cooler near 100%
    for (const r of state.ribbons){
      r.hue = (r.hue + completionHueBias/60) % 360; // slow drift
    }
  }

  function draw(nowS){
    if (state.disposed) return;

    // Always keep the loop alive
    if (!state.inView || document.hidden) {
      requestAnimationFrame(ts => draw(ts/1000));
      return;
    }

    // Host removed? Cleanly tear down.
    if (!document.body.contains(state.host)) { API.dispose(); return; }

    const dt = Math.min(0.05, nowS - state.then); state.then = nowS;

    // faint trail to create “afterimage”
    state.ctx.globalCompositeOperation = 'source-over';
    state.ctx.fillStyle = `rgba(10,12,16,${CFG.trail})`;
    state.ctx.fillRect(0,0,state.W,state.H);

    const L = liveRect();
    const t = nowS;

    applyProgressShaping();

    for(const r of state.ribbons){
      const pathPoints = 120;
      const pct01 = clamp(state.pct/100, 0, 1);
      const thickness = CFG.thickness * (0.8 + 0.4*Math.sin(t*0.7 + r.seed)) * (1 + 0.35*pct01);
      const hue = r.hue;
      const sat = CFG.sat;
      const lig = CFG.light;

      state.ctx.beginPath();
      for(let i=0;i<=pathPoints;i++){
        const u = i / pathPoints;
        const x = L.x + u * L.w;
        const n = noise2D(
          (x + r.seed) * CFG.noiseScale,
          (r.baseY + t*1000*CFG.speed) * CFG.noiseScale
        );
        const y = r.baseY + (n * 60 * r.amp);
        if(i===0) state.ctx.moveTo(x,y);
        else state.ctx.lineTo(x,y);
      }

      const grad = state.ctx.createLinearGradient(L.x, r.baseY, L.x + L.w, r.baseY);
      grad.addColorStop(0.00, hslaStr(hue, sat, lig-6, CFG.alpha*0.55));
      grad.addColorStop(0.45, hslaStr(hue, sat, lig,   CFG.alpha));
      grad.addColorStop(0.85, hslaStr(hue, sat, lig-6, CFG.alpha*0.50));
      grad.addColorStop(1.00, hslaStr(hue, sat, lig-10, 0));

      state.ctx.strokeStyle = grad;
      state.ctx.lineWidth = thickness;
      state.ctx.lineCap = 'round';
      state.ctx.shadowColor = hslaStr(hue, sat, lig, 0.45);
      const blurBoost = (state.mode === MODE.RUNNING) ? Math.floor(10 * clamp(state.pct/100,0,1)) : 0;
      state.ctx.shadowBlur = Math.min(18, 10 + blurBoost);
      state.ctx.stroke();

      r.amp = Math.max(1, r.amp - dt*0.25);
      r.x += (Math.sin(t*0.6 + r.seed)*0.5);
    }

    // ambient autopulse
    if (!state.reduce && nowS >= state.nextPulse){
      pulse(1.0, 8);
      schedulePulse(nowS);
    }

    requestAnimationFrame(ts => draw(ts/1000));
  }

  function syncDPR(){
    const d = Math.max(1, Math.min(2, window.devicePixelRatio||1));
    if (d!==state.DPR){ state.DPR=d; resize(); }
  }

  function setMode(mode){
    if (state.mode === mode) return;
    state.mode = mode;
    switch(mode){
      case MODE.RUNNING: pulse(1.4, 20); break;   // energetic warm bump
      case MODE.DONE:    pulse(0.9, -40); break;  // cool calm
      case MODE.ALERT:   pulse(2.0, -120); break; // red flash
      default:           pulse(0.6, 6);
    }
  }

  function setProgressPercent(p){
    state.pct = clamp(Number(p)||0, 0, 100);
  }

  // -------------------------------
  // Public API
  // -------------------------------
  const API = {
    pulse,
    networkStart(){ if (CFG.reactToAjax) pulse(0.6, 6); },
    networkEnd(ok=true){ if (CFG.reactToAjax) pulse(ok ? 0.8 : 1.1, ok ? 12 : -25); },
    setMode,                     // setMode('running'|'done'|'alert'|'idle')
    setProgressPercent,          // setProgressPercent(0..100)
    dispose(){
      state.disposed = true;
      window.removeEventListener('resize', resize);
      if (state.canvas) state.canvas.remove();
      if (state._dprTimer) { clearInterval(state._dprTimer); state._dprTimer = null; }
      if (state._io) { try{ state._io.disconnect(); }catch(_){} state._io = null; }
      if (state._ro) { try{ state._ro.disconnect(); }catch(_){} state._ro = null; }
    }
  };

  // expose only once
  if (!window.NPPPAurora) window.NPPPAurora = API;

  // -------------------------------
  // Progress wiring (non-invasive)
  // -------------------------------

  // 1) Listen for a push-style custom event (best separation of concerns):
  // dispatchEvent(new CustomEvent('nppp:preload-progress', { detail: { status, checked, total, errors } }))
  window.addEventListener('nppp:preload-progress', (ev)=>{
    try{
      const data = ev.detail || {};
      mapProgressDataToAurora(data);
    }catch(e){}
  });

  // 2) Enhance fetch wrapper to sniff the progress endpoint without breaking callers
  //    We *clone* the Response and parse JSON only when URL matches.
  (function wireFetchSniffer(){
    const endpoint = safeGetProgressEndpoint();
    const pattern = CFG.progressEndpointMatch
      ? new RegExp(CFG.progressEndpointMatch)
      : (endpoint ? new RegExp(escapeRegExp(endpoint)) : null);

    if (!window.fetch || !pattern) return;

    if (!window.__NPPP_AJAX_FETCH_HOOKED2){
      window.__NPPP_AJAX_FETCH_HOOKED2 = true;
      const _fetch = window.fetch;
      window.fetch = function(){
        if (CFG.reactToAjax){ try{ API.networkStart(); }catch(e){} }
        return _fetch.apply(this, arguments)
          .then(res=>{
            if (CFG.reactToAjax){ try{ API.networkEnd(res.ok); }catch(e){} }
            try{
              let url = '';
              try {
                const arg0 = arguments[0];
                if (arg0 instanceof Request) url = arg0.url;
                else if (typeof arg0 === 'string') url = arg0;
                else if (arg0 && typeof arg0 === 'object' && arg0.url) url = String(arg0.url);
              } catch(_) {}

              if (CFG.reactToProgressResponse && url && pattern.test(url)){
                // clone and parse without consuming caller body
                res.clone().json().then((json)=>{
                  mapProgressDataToAurora(json);
                }).catch(()=>{ /* ignore parse errors */ });
              }
            }catch(e){}
            return res;
          })
          .catch(err=>{
            if (CFG.reactToAjax){ try{ API.networkEnd(false); }catch(e){} }
            throw err;
          });
      };
    }
  })();

  function escapeRegExp(s){
    return String(s).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  }

  // Convert REST payload → visual state
  function mapProgressDataToAurora(data){
    if (!data || typeof data!=='object') return;

    const total = Number(data.total) || 0;
    const checked = Number(data.checked) || 0;
    const pct = total > 0 ? clamp(Math.round((checked/total)*100), 0, 100) : (data.status==='done' ? 100 : 0);
    setProgressPercent(pct);

    if (data.status === 'running'){
      setMode(MODE.RUNNING);
      // subtle amplitude proportional to progress
      const amp = lerp(0.8, 1.6, clamp(pct/100, 0, 1));
      pulse(amp, 10);
      if (Number(data.errors) > 0){
        // brief alert ping on errors without leaving RUNNING mode
        pulse(1.8, -110);
      }
    } else if (data.status === 'done'){
      setMode(MODE.DONE);
      setProgressPercent(100);
      pulse(0.9, -42);
    } else {
      setMode(MODE.IDLE);
    }
  }

  // -------------------------------
  // jQuery + XHR auto hooks (keep existing behavior)
  // -------------------------------
  (function wireAjaxHooks(){
    if (window.jQuery && !window.__NPPP_AJAX_JQ_HOOKED2){
      window.__NPPP_AJAX_JQ_HOOKED2 = true;
      jQuery(document).on('ajaxSend', ()=> API.networkStart());
      jQuery(document).on('ajaxComplete', (e, xhr)=> API.networkEnd(xhr ? (xhr.status>=200 && xhr.status<400) : true));
      jQuery(document).on('ajaxError', ()=> API.networkEnd(false));
    }
    if (window.XMLHttpRequest && !window.__NPPP_AJAX_XHR_HOOKED2){
      window.__NPPP_AJAX_XHR_HOOKED2 = true;
      const _open = XMLHttpRequest.prototype.open;
      const _send = XMLHttpRequest.prototype.send;
      XMLHttpRequest.prototype.open = function(){ this.__nppp_listened2=false; return _open.apply(this, arguments); };
      XMLHttpRequest.prototype.send = function(){
        try{ API.networkStart(); }catch(e){}
        if(!this.__nppp_listened2){
          this.addEventListener('loadend', ()=>{ try{ API.networkEnd(this.status>=200 && this.status<400); }catch(e){} }, {once:true});
          this.__nppp_listened2 = true;
        }
        return _send.apply(this, arguments);
      };
    }
  })();

  // -------------------------------
  // Bootstrap
  // -------------------------------
  function start(){
    state.host = pickHost();
    if (!state.host) return;

    state.reduce = !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);

    state.canvas = ensureCanvas();
    state.ctx = state.canvas.getContext('2d', { alpha:true });

    // DPR & size
    state.DPR = Math.max(1, Math.min(2, window.devicePixelRatio||1));
    resize();

    // Events
    window.addEventListener('resize', resize, {passive:true});

    // Observe the host box size (width/height changes)
    const _ro = new ResizeObserver(()=> {
      // Keep DPR in sync (handles zoom) and resize canvas to host box
      syncDPR();
      resize();
    });
    _ro.observe(state.host);
    state._ro = _ro;

    if (state._dprTimer) { clearInterval(state._dprTimer); state._dprTimer = null; }

    // Observe visibility in viewport (pause when offscreen)
    const io = new IntersectionObserver(([e])=>{
      state.inView = !!(e && e.isIntersecting);
    });
    io.observe(state.host);
    state._io = io;

    // Start loop
    const t0 = performance.now()/1000;
    state.then = t0;
    schedulePulse(t0);
    requestAnimationFrame(ts => draw(ts/1000));
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start, {once:true});
  } else {
    start();
  }

})();

/**
 * Aurora Effects Patch (Drop-in)
 * Description: Feature-flagged overlay effects (sheen/crackle/flare) + tempo pulses
 * Version: 2.1.3
 * Author: Hasan CALISIR
 * License: GPL-2.0+
 *
 * Usage: load AFTER core aurora file. No structural changes to the core.
 * Relies on: window.NPPPAurora API, optional 'nppp:preload-progress' events.
 */

(function(){
  'use strict';

  if (!window.NPPPAurora) return; // core must exist

  // Prevent double-wrapping on hot reloads
  if (window.NPPPAurora.__overlayPatched) return;
  window.NPPPAurora.__overlayPatched = true;

  // Stash originals so we can restore on dispose
  let _origSetMode = null;
  let _origSetPct  = null;

  // ------------------------------
  // Configurable feature flags
  // ------------------------------
  const CFG = {
    selector: '.wrap .nppp-header-content, .nppp-header-content',
    effects: {
      sheen:    true,            // animated scanning light while RUNNING
      crackle:  true,            // brief red “static” on errors
      flare:    true,            // one-off cool flare when DONE
      heartbeat:true             // tempo pulses accelerate near completion
    },
    sheenSpeed: 1.0,             // px-ish per frame unit
    sheenAlpha: 0.06,            // peak opacity of sheen band
    crackleAlpha: 0.035,         // per-line alpha
    crackleDecay: 0.07,          // per frame decay
    flareAlpha: 0.12,            // center alpha of finish flare
    flareDecay: 0.04,            // per frame decay
    heartbeatBaseMs: 1800,       // slowest pulse spacing
    heartbeatFastMs:  700,       // fastest pulse spacing near 100%
    tickEveryURLs: 75            // extra pop every N URLs processed
  };

  // ------------------------------
  // Internal patch state
  // ------------------------------
  const S = {
    host: null,
    overlay: null,
    ctx: null,
    W: 0, H: 0,
    DPR: 1,
    running: false,
    pct: 0,
    errors: 0,
    crackle: 0,
    flare: 0,
    lastTick: 0,
    scanT: 0,
    disposed: false,
    nextHeartbeatAt: 0,
    inView: true,
    _io: null,
    _ro: null
  };

  // ------------------------------
  // Host + overlay canvas
  // ------------------------------
  function pickHost(){
    return document.querySelector(CFG.selector);
  }
  function ensureOverlay(host){
    const existing = host.querySelector('.nppp-aurora-overlay');
    if (existing) return existing;
    const c = document.createElement('canvas');
    c.className = 'nppp-aurora-overlay';
    c.style.cssText = 'position:absolute;inset:0;pointer-events:none;display:block;z-index:2;';
    host.appendChild(c);
    return c;
  }
  function syncDPR(){
    const d = Math.max(1, Math.min(2, window.devicePixelRatio||1));
    if (d!==S.DPR){ S.DPR=d; resize(); }
  }
  function resize(){
    if (!S.host || !S.overlay) return;
    const r = S.host.getBoundingClientRect();
    S.W = Math.max(320, r.width|0);
    S.H = Math.max(80,  r.height|0);
    const wPx = (S.W*S.DPR)|0, hPx=(S.H*S.DPR)|0;
    if (S.overlay.width!==wPx) S.overlay.width = wPx;
    if (S.overlay.height!==hPx) S.overlay.height = hPx;
    S.overlay.style.width  = S.W+'px';
    S.overlay.style.height = S.H+'px';
    S.ctx.setTransform(S.DPR,0,0,S.DPR,0,0);
  }

  // ------------------------------
  // Effect updaters
  // ------------------------------
  function onProgressData(data){
    // Accepts your REST payload or a subset
    const total   = Number(data.total)   || 0;
    const checked = Number(data.checked) || 0;
    S.errors = Number(data.errors) || 0;

    const pct = total > 0 ? Math.round((checked/total)*100) : (data.status==='done' ? 100 : S.pct);
    S.pct = Math.max(0, Math.min(100, pct));

    const running = data.status === 'running';
    // tick-based pop
    if (running && CFG.tickEveryURLs > 0){
      const tick = Math.floor(checked / CFG.tickEveryURLs);
      if (tick !== S.lastTick){
        S.lastTick = tick;
        window.NPPPAurora.pulse(1.2, 14); // quick warm pop
      }
    }

    // crackle increase on any errors
    if (S.errors > 0 && CFG.effects.crackle){
      S.crackle = Math.min(1, S.crackle + 0.35);
    }

    // mode change handling for flare
    if (!S.running && running){
      // starting → little kinetic nudge
      window.NPPPAurora.pulse(1.25, 12);
    }
    if (S.running && !running && data.status === 'done' && CFG.effects.flare){
      S.flare = 1; // arm finish flare
      window.NPPPAurora.pulse(0.9, -40); // cool settle
    }
    S.running = running;

    // Heartbeat scheduling
    if (CFG.effects.heartbeat){
      scheduleHeartbeat();
    }
  }

  // Heartbeat pulses speed up as pct → 100
  function scheduleHeartbeat(){
    const now = performance.now();
    if (now < S.nextHeartbeatAt) return;
    const t = Math.max(0, Math.min(1, S.pct/100));
    const interval = CFG.heartbeatBaseMs + (CFG.heartbeatFastMs - CFG.heartbeatBaseMs)*Math.pow(t, 0.8);
    S.nextHeartbeatAt = now + interval;
    if (S.running){
      // gentle amp scaling with progress
      const amp = 0.8 + 0.8*t;
      window.NPPPAurora.pulse(amp, 10);
    }
  }

  // ------------------------------
  // Overlay draw
  // ------------------------------
  function drawOverlay(){
    if (S.disposed) return;

    // Keep the loop alive even while paused
    if (!S.inView || document.hidden) {
      requestAnimationFrame(drawOverlay);
      return;
    }

    // Overlay host removed? Tear down patch + restore API.
    if (!document.body.contains(S.host)) { window.NPPPAuroraPatchDispose?.(); return; }

    // keep DPR + size synced
    syncDPR();

    const ctx = S.ctx;
    ctx.clearRect(0,0,S.W,S.H);

    // SHEEN
    if (CFG.effects.sheen && S.running){
      S.scanT += CFG.sheenSpeed;
      const scanX = ((S.scanT % (S.W + 400)) - 200);
      const g = ctx.createLinearGradient(scanX-80, 0, scanX+80, 0);
      g.addColorStop(0,   'rgba(255,255,255,0)');
      g.addColorStop(0.5, `rgba(255,255,255,${CFG.sheenAlpha})`);
      g.addColorStop(1,   'rgba(255,255,255,0)');
      ctx.globalCompositeOperation = 'lighter';
      ctx.fillStyle = g;
      ctx.fillRect(0, 0, S.W, S.H);
      ctx.globalCompositeOperation = 'source-over';
    }

    // CRACKLE (errors)
    if (CFG.effects.crackle && S.crackle > 0){
      const steps = 24;
      ctx.globalCompositeOperation = 'lighter';
      for (let i=0;i<steps;i++){
        const y = Math.random()*S.H;
        ctx.fillStyle = `rgba(255,40,40,${CFG.crackleAlpha*S.crackle})`;
        ctx.fillRect(0, y|0, S.W, 1);
      }
      ctx.globalCompositeOperation = 'source-over';
      S.crackle = Math.max(0, S.crackle - CFG.crackleDecay);
    }

    // FINISH FLARE
    if (CFG.effects.flare && S.flare > 0){
      const cx = S.W*0.5, cy = S.H*0.5, R = Math.max(S.W,S.H)*0.7;
      const g = ctx.createRadialGradient(cx, cy, 10, cx, cy, R);
      g.addColorStop(0, `rgba(120,200,255,${CFG.flareAlpha*S.flare})`);
      g.addColorStop(1, `rgba(120,200,255,0)`);
      ctx.globalCompositeOperation = 'lighter';
      ctx.fillStyle = g;
      ctx.fillRect(0,0,S.W,S.H);
      ctx.globalCompositeOperation = 'source-over';
      S.flare = Math.max(0, S.flare - CFG.flareDecay);
    }

    // continue loop
    requestAnimationFrame(drawOverlay);
  }

  // ------------------------------
  // Event wiring
  // ------------------------------
  function start(){
    S.host = pickHost();
    if (!S.host) return;

    // Observe visibility in viewport (pause overlay work when offscreen)
    const io = new IntersectionObserver(([e])=>{
      S.inView = !!(e && e.isIntersecting);
    });
    io.observe(S.host);
    S._io = io;

    const reduce = !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
    if (reduce) {
      // soften effects without changing structure
      CFG.sheenAlpha *= 0.5;
      CFG.crackleAlpha *= 0.6;
      CFG.flareAlpha *= 0.6;
    }
    S.overlay = ensureOverlay(S.host);
    S.ctx = S.overlay.getContext('2d', { alpha:true });
    S.DPR = Math.max(1, Math.min(2, window.devicePixelRatio||1));
    resize();

    // Observe host box size and adapt overlay
    const _ro = new ResizeObserver(()=> {
      // DPR may change via zoom; keep it tight
      S.DPR = Math.max(1, Math.min(2, window.devicePixelRatio||1));
      resize();
    });
    _ro.observe(S.host);
    S._ro = _ro;

    window.addEventListener('resize', resize, {passive:true});
    requestAnimationFrame(drawOverlay);

    // Listen to your progress event (preferred)
    window.addEventListener('nppp:preload-progress', ev=>{
      if (ev && ev.detail) onProgressData(ev.detail);
    });

    // Also listen to explicit API calls if you use them
    // (If you set mode/percent directly without the event)
    _origSetMode = window.NPPPAurora.setMode || null;
    if (_origSetMode){
      const _setMode = _origSetMode.bind(window.NPPPAurora);
      window.NPPPAurora.setMode = function(mode){
        const prevRunning = S.running;
        const running = (mode === 'running');
        if (prevRunning && !running){ S.flare = 1; }
        S.running = running;
        return _setMode(mode);
      };
    }

    _origSetPct = window.NPPPAurora.setProgressPercent || null;
    if (_origSetPct){
      const _setPct = _origSetPct.bind(window.NPPPAurora);
      window.NPPPAurora.setProgressPercent = function(p){
        S.pct = Math.max(0, Math.min(100, Number(p)||0));
        if (CFG.effects.heartbeat) scheduleHeartbeat();
        return _setPct(p);
      };
    }
  }

  window.NPPPAuroraPatchDispose = function(){
    try { S.disposed = true; } catch(_) {}
    try { window.removeEventListener('resize', resize); } catch(_) {}
    try { if (S._io) { S._io.disconnect(); S._io = null; } } catch(_) {}
    try { if (S._ro) { S._ro.disconnect(); S._ro = null; } } catch(_) {}
    try { if (S.overlay) { S.overlay.remove(); S.overlay = null; } } catch(_) {}

    // restore API
    try { if (_origSetMode) window.NPPPAurora.setMode = _origSetMode; } catch(_) {}
    try { if (_origSetPct)  window.NPPPAurora.setProgressPercent = _origSetPct; } catch(_) {}

    try { delete window.NPPPAurora.__overlayPatched; } catch(_) {}
  };

  if (document.readyState === 'loading'){
    document.addEventListener('DOMContentLoaded', start, { once:true });
  } else {
    start();
  }

})();
