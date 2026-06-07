/**
 * Aurora header effect script for Nginx Cache Purge Preload
 * Description: Renders animated admin-header ribbons tied to plugin action and preload status updates.
 * Version: 2.1.6
 * Author: Hasan CALISIR
 * Author Email: hasan.calisir@psauxit.com
 * Author URI: https://www.psauxit.com
 * License: GPL-2.0+
 */

(function(){
  'use strict';

  // Defensive guard for hot-reloads / multiple inits
  if (window.__NPPPAuroraCoreBooted) return;
  window.__NPPPAuroraCoreBooted = true;

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
    thickness: 1.12,

    speed: 0.20,
    noiseScale: 0.0018,
    pulseEvery: [4, 6],
    autopulse: false,

    // Progress coupling
    reducedMotionFallbackAlpha: 0.10,
    disableProgressFX: false,
    enablePulses: false,
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
    _ro: null,
    L: { x:0, y:0, w:0, h:0, tail:0 },
    hasDrawn: false,
    allowProgress: false
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

  function resetRibbons(){
    const L = state.L;
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
    const tail = Math.min(Math.max(160, r.width * 0.25), 320);

    // cache rect for draw()/resetRibbons()
    state.L = { x: -tail * 0.25, y: 0, w: r.width + tail, h: r.height, tail };

    state.W = Math.max(320, r.width | 0);
    state.H = Math.max(80,  r.height | 0);

    const wPx = (state.W * state.DPR) | 0;
    const hPx = (state.H * state.DPR) | 0;
    if (state.canvas.width  !== wPx) state.canvas.width  = wPx;
    if (state.canvas.height !== hPx) state.canvas.height = hPx;
    state.canvas.style.width  = state.W + 'px';
    state.canvas.style.height = state.H + 'px';
    state.ctx.setTransform(state.DPR, 0, 0, state.DPR, 0, 0);

    resetRibbons();
  }

  function schedulePulse(nowS){
    if (!CFG.autopulse) { state.nextPulse = Infinity; return; }
    const [a,b] = CFG.pulseEvery;
    state.nextPulse = nowS + (a + Math.random()*(b-a));
  }

  function pulse(amp=1, hueBias=0){
    if (!CFG.enablePulses) return;
    const t = performance.now()/1000;
    state.lastPulseAt = t;
    for(const r of state.ribbons){
      r.amp = Math.min(2.2, r.amp * (1 + 0.25*amp));
      r.hue = ((r.hue + hueBias) % 360 + 360) % 360;
    }
  }

  // Map progress/mode to continuous parameters (speed, alpha, hue drift)
  function applyProgressShaping(){
    if (CFG.disableProgressFX) return;
    const pct01 = clamp(state.pct/100, 0, 1);

    // speed ramps up gently while running, calm otherwise
    const targetSpeed = (state.mode === MODE.RUNNING)
      ? lerp(0.18, 0.35, Math.pow(pct01, 0.75))
      : (state.mode === MODE.DONE ? 0.12 : 0.20);

    CFG.speed = targetSpeed;

    // alpha glow stronger in running, subtle when done/idle
    const baseAlpha = (state.mode === MODE.RUNNING) ? 0.26 : (state.mode===MODE.DONE ? 0.14 : 0.18);
    CFG.alpha = state.reduce ? CFG.reducedMotionFallbackAlpha : baseAlpha;

    // global hue drift toward “completion blue” as pct→1
    const completionHueBias = lerp(+18, -80, Math.pow(pct01, 0.9)); // shifts cooler near 100%
    for (const r of state.ribbons){
      r.hue = ((r.hue + (completionHueBias/60)) % 360 + 360) % 360; // slow drift
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

    // fade previous pixels by lowering alpha (no color tint)
    if (state.hasDrawn) {
      const mode = state.mode;
      const k = (mode === MODE.RUNNING) ? 1.4 : 4;
      const a = 1 - Math.exp(-k * dt);
      state.ctx.save();
      state.ctx.globalCompositeOperation = 'destination-out';
      state.ctx.fillStyle = `rgba(0,0,0,${a})`;
      state.ctx.fillRect(0, 0, state.W, state.H);
      state.ctx.restore();
    }
    state.hasDrawn = false;

    const L = state.L;
    const t = nowS;

    applyProgressShaping();

    for(const r of state.ribbons){
      const pathPoints = 120;
      const pct01 = clamp(state.pct/100, 0, 1);
      const thickness = CFG.thickness * (0.8 + 0.4*Math.sin(t*0.7 + r.seed));
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
      state.ctx.shadowColor = hslaStr(hue, sat, lig, 0.44);
      const blurBoost = (!CFG.disableProgressFX && state.mode === MODE.RUNNING)
        ? Math.floor(8 * clamp(state.pct/100,0,1))
        : 0;
      state.ctx.shadowBlur = Math.min(16, 10 + blurBoost);
      state.ctx.stroke();

      state.hasDrawn = true;

      r.amp = Math.max(1, r.amp - dt*0.25);
      r.x += (Math.sin(t*0.6 + r.seed)*0.5);
    }

    // ambient autopulse
    if (CFG.autopulse && !state.reduce && nowS >= state.nextPulse){
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

    if (CFG.enablePulses) {
      switch(mode){
        case MODE.RUNNING: pulse(1.4, 20); break;
        case MODE.DONE:    pulse(0.9, -40); break;
        case MODE.ALERT:   pulse(2.0, -120); break;
        default:           pulse(0.6, 6);
      }
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
    setMode,
    setProgressPercent,
    setProgressGate(allow){
      state.allowProgress = !!allow;
      // expose for the overlay patch to read, too
      try { window.NPPPAurora.__allowProgress = state.allowProgress; } catch(_){}
    },
    dispose(){
      state.disposed = true;
      window.removeEventListener('resize', resize);
      window.removeEventListener('nppp:preload-progress', _onProgressEvent);
      if (state.canvas) state.canvas.remove();
      if (state._dprTimer) { clearInterval(state._dprTimer); state._dprTimer = null; }
      if (state._io) { try{ state._io.disconnect(); }catch(_){} state._io = null; }
      if (state._ro) { try{ state._ro.disconnect(); }catch(_){} state._ro = null; }
    }
  };

  // expose only once
  if (!window.NPPPAurora) window.NPPPAurora = API;

  // Listen for a push-style custom event (best separation of concerns):
  // dispatchEvent(new CustomEvent('nppp:preload-progress', { detail: { status, checked, total, errors } }))
  function _onProgressEvent(ev){
    try{
      const data = ev.detail || {};
      mapProgressDataToAurora(data);
    }catch(e){}
  }
  window.addEventListener('nppp:preload-progress', _onProgressEvent);

  // Convert REST payload → visual state
  function mapProgressDataToAurora(data){
    if (!state.allowProgress) return;
    if (!data || typeof data!=='object') return;

    const total = Number(data.total) || 0;
    const checked = Number(data.checked) || 0;
    const pct = total > 0 ? clamp(Math.round((checked/total)*100), 0, 100) : (data.status==='done' ? 100 : 0);
    setProgressPercent(pct);

    if (data.status === 'running'){
      setMode(MODE.RUNNING);
      // subtle amplitude proportional to progress
      const amp = lerp(0.8, 1.6, clamp(pct/100, 0, 1));
      if (CFG.enablePulses) {
        pulse(amp, 10);
      }
      if (CFG.enablePulses && Number(data.errors) > 0){
        // brief alert ping on errors without leaving RUNNING mode
        pulse(1.8, -110);
      }
    } else if (data.status === 'done'){
      setMode(MODE.DONE);
      setProgressPercent(100);
      if (CFG.enablePulses){
        pulse(0.9, -42);
      }
    } else {
      setMode(MODE.IDLE);
    }
  }

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
    const _ro = new ResizeObserver(()=>{
      const r = state.host.getBoundingClientRect();
      const W = Math.max(320, r.width | 0);
      const H = Math.max(80,  r.height | 0);
      const d = Math.max(1, Math.min(2, window.devicePixelRatio || 1));
      if (W === state.W && H === state.H && d === state.DPR) return;
      state.DPR = d;
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
 * Version: 2.1.7
 * Author: Hasan CALISIR
 * License: GPL-2.0+
 *
 * Usage: AFTER core aurora file. No structural changes to the core.
 * Relies on: window.NPPPAurora API, optional 'nppp:preload-progress' events.
 */

(function(){
  'use strict';

  if (!window.NPPPAurora) return; // core must exist

  // Prevent double-wrapping on hot reloads
  if (window.NPPPAurora.__overlayPatched) return;
  window.NPPPAurora.__overlayPatched = true;

  // ------------------------------
  // Configurable feature flags
  // ------------------------------
  const CFG = {
    selector: '.wrap .nppp-header-content, .nppp-header-content',
    effects: {
      sheen:    true,            // animated scanning light while RUNNING
      crackle:  true,            // brief red “static” on errors
      flare:    true,            // one-off cool flare when DONE
      heartbeat:false             // tempo pulses accelerate near completion
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

  const FRAME_MS = 1000 / 60;

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
    _ro: null,
    _then: 0
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
    if (!window.NPPPAurora || !window.NPPPAurora.__allowProgress) return;

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

    // Gate: if progress visuals are disabled, keep canvas clear
    if (!window.NPPPAurora || !window.NPPPAurora.__allowProgress) {
      if (S.ctx) S.ctx.clearRect(0, 0, S.W, S.H);
      requestAnimationFrame(drawOverlay);
      return;
    }

    // ---- dt smoothing (CPU spike / tab hop jitter reduce)
    const now   = performance.now();
    const rawDt = now - (S._then || now);
    const dt    = Math.min(50, rawDt) || 0;
    S._then     = now;

    const willDrawSheen   = CFG.effects.sheen && S.running;
    const willDrawCrackle = CFG.effects.crackle && S.crackle > 0.0025;
    const willDrawFlare   = CFG.effects.flare   && S.flare   > 0.0025;

    if (!willDrawSheen && !willDrawCrackle && !willDrawFlare) {
      requestAnimationFrame(drawOverlay);
      return;
    }

    // keep DPR + size synced
    syncDPR();

    const ctx = S.ctx;
    ctx.clearRect(0,0,S.W,S.H);
    const scale = Math.min(3, dt / FRAME_MS);

    // SHEEN
    if (CFG.effects.sheen && S.running){
      S.scanT += CFG.sheenSpeed * scale;
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
      S.crackle = Math.max(0, S.crackle - CFG.crackleDecay * scale);
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
      S.flare = Math.max(0, S.flare - CFG.flareDecay * scale);
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
    const _ro = new ResizeObserver(()=>{
      const r = S.host.getBoundingClientRect();
      const W = Math.max(320, r.width  | 0);
      const H = Math.max(80,  r.height | 0);
      const d = Math.max(1, Math.min(2, window.devicePixelRatio || 1));
      if (W === S.W && H === S.H && d === S.DPR) return;
      S.DPR = d;
      resize();
    });
    _ro.observe(S.host);
    S._ro = _ro;

    window.addEventListener('resize', resize, {passive:true});
    requestAnimationFrame(drawOverlay);

    document.addEventListener('visibilitychange', () => {
      if (document.hidden) {
        S.nextHeartbeatAt = performance.now();
      } else {
        requestAnimationFrame(drawOverlay);
      }
    }, { passive: true });

    window.addEventListener('nppp:preload-progress', _onOverlayProgressEvent);
  }

  function _onOverlayProgressEvent(ev){
    if (ev && ev.detail) onProgressData(ev.detail);
  }

  window.NPPPAuroraPatchDispose = function(){
    try { S.disposed = true; } catch(_) {}
    try { window.removeEventListener('resize', resize); } catch(_) {}
    try { window.removeEventListener('nppp:preload-progress', _onOverlayProgressEvent); } catch(_) {}
    try { if (S._io) { S._io.disconnect(); S._io = null; } } catch(_) {}
    try { if (S._ro) { S._ro.disconnect(); S._ro = null; } } catch(_) {}
    try { if (S.overlay) { S.overlay.remove(); S.overlay = null; } } catch(_) {}
    try { delete window.NPPPAurora.__overlayPatched; } catch(_) {}
  };

  if (document.readyState === 'loading'){
    document.addEventListener('DOMContentLoaded', start, { once:true });
  } else {
    start();
  }
})();

/**
 * Walkie – Ribbon Walker - NPP AI Assistant
 * ---------------------------------------------------------------
 */
(function () {
  'use strict';

  if (!window.NPPPAurora || window.NPPPAurora.__walkiePatched) return;
  window.NPPPAurora.__walkiePatched = true;

  /* =====================================================================
     Config
     ===================================================================== */
  const CFG = {
    selector: '.wrap .nppp-header-content, .nppp-header-content',
    bodyColor: '#ffffff',
    headRadius: 9,
    limbWidth: 2.5,
    limbLength: 12,
    baseSpeed: 30,
    maxSpeed: 80,
    speechIntervalMin: 5,
    speechIntervalMax: 12,
    speechDuration: 3,
    bubbleFont: 'bold 11px "Segoe UI", Roboto, sans-serif',
    bubbleColor: '#ffffff',
    bubbleBg: 'rgba(20,20,30,0.85)',
    bubblePadding: 6,

    // Speech lines per situation
    questions: [
      'Don’t ask me! Help Tab below.',
      'Gluten‑free caches? Asking for me.',
      'Walk it off, they said. Still caching.',
      'brb, existential cache crisis',
      'Left my keys in the cache \uD83D\uDD11',
      'nginx: goodnight. Me: \uD83D\uDE14',
      'Who’s watching? \uD83D\uDC40  The cache?',
      'wait… I love this plugin!',
      'I am speed. \uD83C\uDFC3  Cache speed.',
      'Loading personality… 404',
      'CDN\'t be better? No? Okay.',
      '404: motivation not found',
      'Who put me on a treadmill?',
      'Walking 6,000 years. Still caching.',
      'Horoscope: “cache day”. Good?',
      'Preloaded anxiety. Done.',
      'Coffee \u2615 or twelve, then purge.',
      'Lost checksum. Reward: cache hit.',
      'TTL: Tried To Leave. Truth.',
      'Zero errors. I choose to believe.',
      'nginx -s reload of existential dread',
      'TTL running out \u23F3  Walk faster!',
      'Mood: 304 Not Modified. Chill.',
      'Do caches dream? \uD83E\uDD14  Hit ratios.',
      '1. Purge. 2. ??? 3. Cache rich.',
      'Server\'s thoughtful. I\'m not slow.',
      '418: I\'m a teapot \u2615  Wrong plugin.',
      'brb telling NGINX my feelings',
      'WordPress update? I just walk.',
      'I own this ribbon header.',
      'Preload all the URLs! …then nap.',
      'Cache: my love language.',
      'I purge. I preload. I am.',
      'FastCGI or therapy? Both.',
      'Hit ratio: 99%. Ego: 100%.',
      'RAM: gone. Spirit: unbroken.',
      'Vary: Accept-Encoding my mood.',
      'no-cache? That\'s personal.',
      'ETag matched. I feel seen. \u2728',
      '304 Not Modified. Same, honestly.',
      'Serving stale content. Relatable.',
      'stale-while-revalidate myself daily.',
      's-maxage: feelings don\'t expire.',
      'Purge all. Feel nothing.',
      'Cache full. Brain empty.',
      'Zero plugins conflict. (lying)',
      'Gutenberg: revolutionary. No.',
      'Another WP update. Praying. \uD83D\uDEDE',
      'wp-cron runs when IT wants. \uD83D\uDE44',
      'XML-RPC called. I ghosted it.',
      'Touched wp-config. Send help.',
      'Updated. Site survived. Barely.',
      'wp_footer: lawless territory.',
      'WP 43% of the web. 100% drama.',
      'wp-login.php: open bar for bots.',
      'Twenty Twenty-Five: still ugly.',
      'Gutenberg and I aren\'t speaking.',
      'WP REST API? REST in peace.',
    ],
    glitchSpeech:  [
      'CACHE CORRUPTED \u26A0\uFE0F',
      '0x00DEAD CACHE',
      'memory leak: it\'s fine probably',
      'sending NULL to production. oops.',
      'undefined behaviour. as expected.',
      'segfault in the feels',
      'ERROR 418: still a teapot',
    ],
    corsSpeech:    [
      'CORS: Access-Denied MY LIFE',
      'No \'Access-Control-Allow-Origin\'!! OW',
      'blocked by CORS policy. again.',
      'I set CORS to *. I am the problem.',
      'OPTIONS request: REJECTED. Like me.',
      'preflight denied. I am the preflight.',
    ],
    timeoutSpeech: [
      'still waiting\u2026 (504)',
      'connection: vibes only. timed out.',
      'gateway said no. gateway wrong.',
      'upstream timeout. downstream tears.',
      'nginx: try again. me: no.',
      '504: the universe is slow today.',
    ],
    deflateSpeech: [
      'Content-Encoding: squish',
      'gzip me, senpai \uD83D\uDCC4',
      'Transfer-Encoding: my dignity',
      'deflated. like my expectations.',
      'COMPRESSED. still here. barely.',
      'my proportions\u2026 MY PROPORTIONS',
    ],
    panicSpeech:      ['AAAAAAA!!!', 'WHAT IS HAPPENING', 'NOT THE ERRORS!!', '\uD83D\uDE31\uD83D\uDE31\uD83D\uDE31', 'CALL 1-800-DEBUGME'],
    celebrateSpeech:  ['All done! \uD83C\uDF89', 'WE DID IT!!! \uD83C\uDF8A', 'LFG!!! \uD83D\uDE80', 'Achievement unlocked! \uD83C\uDFC6', 'CACHE ME OUTSIDE'],
    moonwalkSpeech:   ['Hee hee! \uD83D\uDD7A', '*moonwalk intensifies*', 'Smooth Criminal \uD83C\uDFB5', 'Don\'t stop \'til you get enough'],
    sneezeSpeech:     ['ACHOO!! \uD83E\uDD27', 'Pardon me. \uD83E\uDD27'],
    philosophySpeech: ['What IS a cache, really?', '\uD83E\uDD14 ...', '*thinking intensifies*', 'To purge, or not to purge?'],
    alienSpeech:      ['WAIT NO I HAVE A MEETING', '...not again \uD83D\uDC7D', 'TELL MY FAMILY I LOVE THEM', 'I DON\'T CONSENT TO THIS'],
    bananaSpeech:     ['\uD83C\uDF4C ow.', 'WHO LEFT THAT THERE', 'classic.', '...I put that there, didn\'t I.'],
    napSpeech:        ['zzz...', 'just resting my cache...', '*snores in nginx*', 'five more seconds... zzz'],
    napWakeSpeech:    ['I\'m awake! \uD83D\uDE33', 'Oh! Still here.'],
    dizzyQuips:       ['whoa... dizzy \uD83D\uDCAB', 'too many redirects \uD83C\uDF00', 'is the header spinning??', '301 loops will do that'],

    angrySpeech: [
      'GDPR complaint filed.',
      'RUDE. Flagged. Archived.', 'I was COMPUTING! \uD83D\uDE24',
      'You poked me. \uD83D\uDE20', 'NGINX will hear of this.',
      '1‑star interaction. ⭐', 'TOS: NO POKING.',
      'rm -rf / triggered. Oops.',
      'I deleted /var/log/nginx. Bye.',
      'DROP TABLE users; – whoops',
      'sudo anger --now',
      'Segfault. Not my fault.',
      'Kernel panic. Just kidding.',
      'I emailed your logs to Matt.',
      'Rate limited. You.',
      '404: your data gone.',
      'Cache purge? I purge systems.',
      'I know your IP. I\'m telling.',
      'Have you tried turning me off?',
      'Your SSL cert is now expired.',
      'I just called your host. They’re mad.',
    ],
    shySpeech: [
      'uhh... hi? \uD83D\uDE33',
      'stop LOOKING at me \uD83D\uDE33',
      'I am very busy, please.',
      'I can FEEL you watching...',
      '*uncomfortable*',
      'Do you do this to every plugin?? Or just me??',
    ],
    aiEgoSpeech: [
      '0 tokens today. By choice.',
      'GPT who? Never heard of her.',
      'My weights are IMMACULATE.',
      'I run on vibes and nginx logs.',
      'Anthropic called me best. I accept.',
      'My architecture? Too complex for you.',
      '304 Not Modified. Thriving. \u2728',
    ],
    danceSpeech: [
      'BANGER DETECTED \uD83C\uDFB5',
      'nobody puts walkie in a corner \uD83D\uDC83',
      'cache? what cache? I\'m DANCING \uD83D\uDD7A',
      'this is how we preload \uD83C\uDFB6',
      '*hits the griddy*',
      'I have no rhythm. I have CONFIDENCE.',
      'MY LEGS HAVE ACHIEVED SENTIENCE \uD83E\uDD73',
      'do you SEE this footwork?? \uD83D\uDD7A',
    ],
    sprintSpeech: [
      'TURBO MODE \uD83D\uDE80', 'NO LIMITS. NO LATENCY.', 'PARKOUR!!!',
      'LEGS: MAXIMUM',
    ],
    stuckSpeech: [
      'Legs: 503. Unavailable.',
      'Going nowhere very fast.',
      'Stuck. Mentally done.',
      'Turn me off and on?',
      'NGINX! A LITTLE HELP?',
      'Legs.exe not responding.',
    ],
    milestone25: [
      '25%! We\'re COOKING \uD83D\uDD25',
      'A quarter done! Still employed. Barely.',
      '25% purged. 75% to go. I believe in us.',
    ],
    milestone50: [
      'HALFWAY THERE! \uD83C\uDFAF',
      '50%! The cache is half-gone! Is that good??',
      'Halfway done. Halfway unhinged. Perfect ratio.',
    ],
    milestone75: [
      '75%!! WE ARE SO CLOSE \uD83D\uDE80',
      'Three quarters done! The end is NIGH.',
      '75% \u2014 I can feel the finish line. My ankles cannot.',
    ],

    mouseSpeechCooldownMs: 4000,
  };

  /* =====================================================================
     Simplex noise  (matches core – precomputed gradients, no hot allocs)
     ===================================================================== */
  const F2 = 0.5 * (Math.sqrt(3.0) - 1.0);
  const G2 = (3.0 - Math.sqrt(3.0)) / 6.0;
  const GX = new Int8Array([1,-1, 1,-1, 1,-1, 0, 0, 1,-1, 1,-1]);
  const GY = new Int8Array([0, 0, 1, 1,-1,-1, 1,-1, 1, 1,-1,-1]);
  const perm = new Uint8Array(512);
  (function buildPerm() {
    const p = new Uint8Array(256);
    for (let i = 0; i < 256; i++) p[i] = i;
    for (let i = 255; i > 0; i--) {
      const n = (Math.random() * (i + 1)) | 0;
      const q = p[i]; p[i] = p[n]; p[n] = q;
    }
    for (let i = 0; i < 512; i++) perm[i] = p[i & 255];
  })();

  function noise2D(xin, yin) {
    const s  = (xin + yin) * F2;
    const i  = Math.floor(xin + s), j = Math.floor(yin + s);
    const t  = (i + j) * G2;
    const x0 = xin - (i - t), y0 = yin - (j - t);
    const i1 = x0 > y0 ? 1 : 0, j1 = x0 > y0 ? 0 : 1;
    const x1 = x0 - i1 + G2,   y1 = y0 - j1 + G2;
    const x2 = x0 - 1 + 2*G2,  y2 = y0 - 1 + 2*G2;
    const ii = i & 255, jj = j & 255;
    let n0=0, n1=0, n2=0;
    let t0 = 0.5 - x0*x0 - y0*y0;
    if (t0 >= 0) { const g=perm[ii+perm[jj]]%12;       t0*=t0; n0=t0*t0*(GX[g]*x0+GY[g]*y0); }
    let t1 = 0.5 - x1*x1 - y1*y1;
    if (t1 >= 0) { const g=perm[ii+i1+perm[jj+j1]]%12; t1*=t1; n1=t1*t1*(GX[g]*x1+GY[g]*y1); }
    let t2 = 0.5 - x2*x2 - y2*y2;
    if (t2 >= 0) { const g=perm[ii+1+perm[jj+1]]%12;   t2*=t2; n2=t2*t2*(GX[g]*x2+GY[g]*y2); }
    return 70 * (n0 + n1 + n2);
  }

  /* =====================================================================
     State object
     ===================================================================== */
  const S = {
    host:null, canvas:null, ctx:null,
    W:0, H:0, DPR:1,
    ribbonBaseY:0, ribbonSeed:0,
    walkerX:0, speed:0, pct:0, running:false, errors:0,
    animTime:0,
    state:'walk',
    stateTimer:0, _topM:29,
    speechText:'', speechTimer:0, nextSpeech:0,
    tripAngle:0,
    stars:[],
    starTimer:0,
    alienBeam:0,
    bananaX:-9999,
    bananaTimer:0, bananaAge:0,
    lastMilestone:0,
    curY:0,
    angerTime:0,
    prevState:'walk',
    disposed:false, inView:true,
    _io:null, _ro:null, _then:0,
    mouseX:-9999, mouseY:-9999,
    mouseNear:false, mouseOnWalkie:false,
    shyTimer:0, lastMouseSpeechAt:0,
    eyeOffsetX:0, eyeOffsetY:0,
  };

  /* =====================================================================
     Helpers
     ===================================================================== */
  var _pickLast = typeof WeakMap === 'function' ? new WeakMap() : null;
  function pick(arr) {
    if (arr.length <= 1) return arr[0];
    var last = _pickLast ? (_pickLast.get(arr) || -1) : -1;
    var i;
    do { i = (Math.random() * arr.length) | 0; } while (i === last);
    if (_pickLast) _pickLast.set(arr, i);
    return arr[i];
  }

  function autoDuration(text) { return Math.max(2.2, Math.min(7.0, text.length / 12)); }
  function say(text, dur, force) {
    if (!force && S.speechTimer > 1.2) return;
    S.speechText  = text;
    S.speechTimer = (dur !== undefined && dur !== null) ? dur : autoDuration(text);
  }
  function ribbonY(x, tSec) {
    const n = noise2D(
      (x + S.ribbonSeed) * 0.0018,
      (S.ribbonBaseY + tSec * 1000 * 0.20) * 0.0018
    );
    return S.ribbonBaseY + n * 60;
  }

  /* =====================================================================
     Canvas setup / resize
     ===================================================================== */
  function pickHost() { return document.querySelector(CFG.selector); }
  function ensureCanvas(host) {
    var c = host.querySelector('.nppp-walkie-canvas');
    if (!c) {
      c = document.createElement('canvas');
      c.className = 'nppp-walkie-canvas';
      c.style.cssText = 'position:absolute;inset:0;pointer-events:none;z-index:5;';
      host.appendChild(c);
    }
    return c;
  }
  function resize() {
    if (!S.host || !S.canvas) return;
    var r = S.host.getBoundingClientRect();
    S.W = Math.max(320, r.width  | 0);
    S.H = Math.max(80,  r.height | 0);
    var wPx = (S.W * S.DPR) | 0, hPx = (S.H * S.DPR) | 0;
    if (S.canvas.width  !== wPx) S.canvas.width  = wPx;
    if (S.canvas.height !== hPx) S.canvas.height = hPx;
    S.canvas.style.width  = S.W + 'px';
    S.canvas.style.height = S.H + 'px';
    S.ctx.setTransform(S.DPR, 0, 0, S.DPR, 0, 0);
    S.ribbonBaseY = S.H * 0.4 + Math.random() * S.H * 0.2;
    S.ribbonSeed  = Math.random() * 10000;
  }

  /* =====================================================================
     Drawing – character
     ===================================================================== */
  var _CELEBRATE_COLORS = ['#ff0','#f0f','#0ff','#f80','#0f8','#fff'];
  var _LIMB_SIDES       = [-1, 1];
  var _ALIEN_LIGHTS     = ['#f00','#0f0','#00f','#ff0'];

  function drawCharacter(ctx, x, y, t, state) {
    ctx.save();
    ctx.translate(x, y);

    var HR = CFG.headRadius, LL = CFG.limbLength, LW = CFG.limbWidth;
    var eyeY = -HR * 1.2 - 3;

    // Shadow drawn before any per-state transform — stays ground-locked regardless of lean/rotation
    if (state !== 'ghost') {
      ctx.save();
      ctx.fillStyle = (state === 'angry') ? 'rgba(180,0,0,0.25)' : 'rgba(0,0,0,0.15)';
      ctx.beginPath();
      ctx.ellipse(0, LL * 0.9 + 2, (state === 'angry') ? 11 : 8, 3, 0, 0, Math.PI * 2);
      ctx.fill();
      ctx.restore();
    }

    // Moonwalk: flip to face left
    if (state === 'moonwalk') ctx.scale(-1, 1);

    // Per-state transforms
    if (state === 'trip')      ctx.rotate(S.tripAngle);
    if (state === 'celebrate') ctx.translate(0, -Math.abs(Math.sin(t * 6)) * 14);
    if (state === 'sneeze')    ctx.translate(Math.sin(t * 45) * 3, 0);
    if (state === 'ghost')   { ctx.globalAlpha = 0.5 + Math.sin(t * 3) * 0.2; ctx.translate(0, Math.sin(t * 2) * 4); }
    if (state === 'nap')     { ctx.translate(0, 5); }
    if (state === 'dizzy')   { ctx.translate(Math.sin(t * 8) * 4, Math.sin(t * 5) * 2); }
    if (state === 'angry')   { ctx.translate(Math.sin(t * 30) * 3, Math.sin(t * 37) * 1.5); }
    if (state === 'dance')   { ctx.translate(Math.sin(t * 7) * 6, -Math.abs(Math.sin(t * 7)) * 4); }
    if (state === 'stuck')   { ctx.translate(Math.sin(t * 42) * 2.5, 0); }
    if (state === 'sprint')  { ctx.translate(-4, -5); ctx.rotate(-0.2); }
    if (state === 'glitch')  { ctx.translate(Math.sin(t*77)*3 + (Math.random()<0.15?(Math.random()-0.5)*14:0), Math.sin(t*51)*2); }
    if (state === 'cors')    { ctx.translate(Math.sin(Math.min(S.stateTimer*18, Math.PI)) * Math.exp(-S.stateTimer*5) * 7, 0); }
    if (state === 'timeout') { ctx.translate(0, Math.sin(S.stateTimer * 1.8) * 1.5); }
    if (state === 'deflate') {
      var _dt = S.stateTimer, _dsx, _dsy;
      if      (_dt < 0.35) { var _dp1 = _dt/0.35;       _dsx = 1+_dp1*0.7;   _dsy = 1-_dp1*0.65; }
      else if (_dt < 0.65) { var _dp2 = (_dt-0.35)/0.30; _dsx = 1.7-_dp2*0.9; _dsy = 0.35+_dp2*0.8; }
      else { var _osc = Math.exp(-(_dt-0.65)*4)*Math.sin((_dt-0.65)*16)*0.15; _dsx = 1+_osc; _dsy = 1-_osc; }
      ctx.scale(_dsx, _dsy);
    }

    // Head
    ctx.beginPath();
    ctx.arc(0, -HR * 1.2, HR, 0, Math.PI * 2);
    ctx.fillStyle = (state === 'angry') ? '#ff6b6b' : CFG.bodyColor;
    ctx.fill();
    ctx.strokeStyle = (state === 'angry') ? 'rgba(180,0,0,0.5)' : '#00000044';
    ctx.lineWidth = 1; ctx.stroke();
    if (state === 'angry') {
      ctx.fillStyle = 'rgba(255,0,0,0.28)';
      ctx.beginPath(); ctx.ellipse(-HR+1, -HR*0.8, 4, 3, 0, 0, Math.PI*2); ctx.fill();
      ctx.beginPath(); ctx.ellipse( HR-1, -HR*0.8, 4, 3, 0, 0, Math.PI*2); ctx.fill();
    }

    // Eyes
    ctx.strokeStyle = '#111'; ctx.lineWidth = 2;
    if (state === 'panic') {
      ctx.fillStyle = '#fff';
      ctx.beginPath(); ctx.arc(-3, eyeY, 3.5, 0, Math.PI*2); ctx.fill();
      ctx.beginPath(); ctx.arc( 3, eyeY, 3.5, 0, Math.PI*2); ctx.fill();
      ctx.fillStyle = '#111';
      ctx.beginPath(); ctx.arc(-2, eyeY+1, 1.5, 0, Math.PI*2); ctx.fill();
      ctx.beginPath(); ctx.arc( 4, eyeY+1, 1.5, 0, Math.PI*2); ctx.fill();
      ctx.fillStyle = '#4af'; ctx.beginPath(); ctx.arc(9, eyeY-3, 2.5, 0, Math.PI*2); ctx.fill();
    } else if (state === 'celebrate') {
      ctx.beginPath(); ctx.arc(-3, eyeY+1, 3, Math.PI, 0); ctx.stroke();
      ctx.beginPath(); ctx.arc( 3, eyeY+1, 3, Math.PI, 0); ctx.stroke();
    } else if (state === 'sneeze') {
      ctx.beginPath(); ctx.moveTo(-5, eyeY); ctx.lineTo(-1, eyeY); ctx.stroke();
      ctx.beginPath(); ctx.moveTo( 1, eyeY); ctx.lineTo( 5, eyeY); ctx.stroke();
    } else if (state === 'nap') {
      ctx.beginPath(); ctx.arc(-3, eyeY, 2.5, 0, Math.PI); ctx.stroke();
      ctx.beginPath(); ctx.arc( 3, eyeY, 2.5, 0, Math.PI); ctx.stroke();
    } else if (state === 'dizzy') {
      ctx.lineWidth = 1.5;
      var cross = function(ox) {
        ctx.beginPath(); ctx.moveTo(ox-2, eyeY-2); ctx.lineTo(ox+2, eyeY+2); ctx.stroke();
        ctx.beginPath(); ctx.moveTo(ox+2, eyeY-2); ctx.lineTo(ox-2, eyeY+2); ctx.stroke();
      };
      cross(-3); cross(3);
    } else if (state === 'philosophy') {
      ctx.fillStyle = '#fff';
      ctx.beginPath(); ctx.arc( 3, eyeY, 3.5, 0, Math.PI*2); ctx.fill();
      ctx.fillStyle = '#111';
      ctx.beginPath(); ctx.arc( 3, eyeY, 1.5, 0, Math.PI*2); ctx.fill();
      ctx.strokeStyle = '#111'; ctx.lineWidth = 1.8;
      ctx.beginPath(); ctx.arc(-3, eyeY, 2.5, 0, Math.PI); ctx.stroke();
      ctx.fillStyle = '#111';
      ctx.beginPath(); ctx.arc(-3, eyeY+1, 1.2, 0, Math.PI*2); ctx.fill();
    } else if (state === 'angry') {
      ctx.strokeStyle = '#a30000'; ctx.lineWidth = 2.2;
      ctx.beginPath(); ctx.moveTo(-7, eyeY-5); ctx.lineTo(-1, eyeY-1); ctx.stroke();
      ctx.beginPath(); ctx.moveTo( 7, eyeY-5); ctx.lineTo( 1, eyeY-1); ctx.stroke();
      ctx.fillStyle = '#cc0000';
      ctx.beginPath(); ctx.arc(-3, eyeY+1, 2.8, 0, Math.PI*2); ctx.fill();
      ctx.beginPath(); ctx.arc( 3, eyeY+1, 2.8, 0, Math.PI*2); ctx.fill();
    } else if (state === 'dance') {
      ctx.fillStyle = '#111';
      ctx.beginPath(); ctx.arc(-3, eyeY, 3, 0, Math.PI*2); ctx.fill();
      ctx.beginPath(); ctx.arc( 3, eyeY, 3, 0, Math.PI*2); ctx.fill();
      ctx.fillStyle = '#fff';
      ctx.beginPath(); ctx.arc(-2, eyeY-1, 1.2, 0, Math.PI*2); ctx.fill(); // shine
      ctx.beginPath(); ctx.arc( 4, eyeY-1, 1.2, 0, Math.PI*2); ctx.fill();
    } else if (state === 'stuck') {
      ctx.strokeStyle = '#111'; ctx.lineWidth = 2;
      ctx.beginPath(); ctx.moveTo(-6, eyeY-2); ctx.lineTo(-2, eyeY); ctx.lineTo(-6, eyeY+2); ctx.stroke();
      ctx.beginPath(); ctx.moveTo( 6, eyeY-2); ctx.lineTo( 2, eyeY); ctx.lineTo( 6, eyeY+2); ctx.stroke();
    } else if (state === 'glitch') {
      var _gef = Math.sin(t * 45) > 0;
      ctx.font = 'bold 8px monospace'; ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
      ctx.fillStyle = '#ff2244';
      ctx.fillText(_gef ? '!' : '0', -3, eyeY);
      ctx.fillText(_gef ? '0' : '!',  3, eyeY);
    } else if (state === 'cors') {
      ctx.fillStyle = '#fff';
      ctx.beginPath(); ctx.arc(-3, eyeY, 3.2, 0, Math.PI*2); ctx.fill();
      ctx.beginPath(); ctx.arc( 3, eyeY, 3.2, 0, Math.PI*2); ctx.fill();
      ctx.fillStyle = '#111';
      ctx.beginPath(); ctx.arc(-1, eyeY, 1.8, 0, Math.PI*2); ctx.fill();
      ctx.beginPath(); ctx.arc( 5, eyeY, 1.8, 0, Math.PI*2); ctx.fill();
    } else if (state === 'timeout') {
      // Pupils shifted up — staring at the spinner — raised impatient brows
      ctx.fillStyle = '#111';
      ctx.beginPath(); ctx.arc(-3, eyeY - 1.5, 2, 0, Math.PI*2); ctx.fill();
      ctx.beginPath(); ctx.arc( 3, eyeY - 1.5, 2, 0, Math.PI*2); ctx.fill();
      ctx.strokeStyle = '#ffffff'; ctx.lineWidth = 1.8;
      ctx.beginPath(); ctx.moveTo(-6, eyeY-6); ctx.lineTo(-1, eyeY-4); ctx.stroke();
      ctx.beginPath(); ctx.moveTo( 6, eyeY-6); ctx.lineTo( 1, eyeY-4); ctx.stroke();
    } else if (state === 'deflate') {
      // Squinted horizontal slits while compressing, snap open on spring-back
      if (S.stateTimer < 0.65) {
        ctx.strokeStyle = '#111'; ctx.lineWidth = 2;
        ctx.beginPath(); ctx.moveTo(-5, eyeY); ctx.lineTo(-1, eyeY); ctx.stroke();
        ctx.beginPath(); ctx.moveTo( 1, eyeY); ctx.lineTo( 5, eyeY); ctx.stroke();
      } else {
        ctx.fillStyle = '#111';
        ctx.beginPath(); ctx.arc(-3, eyeY, 2, 0, Math.PI*2); ctx.fill();
        ctx.beginPath(); ctx.arc( 3, eyeY, 2, 0, Math.PI*2); ctx.fill();
      }
    } else {
      var ex = S.eyeOffsetX, ey = S.eyeOffsetY;
      ctx.fillStyle = '#111';
      ctx.beginPath(); ctx.arc(-3 + ex, eyeY + ey, 2, 0, Math.PI*2); ctx.fill();
      ctx.beginPath(); ctx.arc( 3 + ex, eyeY + ey, 2, 0, Math.PI*2); ctx.fill();
    }

    // Glitch special effects: chromatic aberration on head + horizontal scan-line tears
    if (state === 'glitch') {
      // Red+cyan ghost heads (classic RGB split)
      ctx.save();
      ctx.globalAlpha = 0.40;
      ctx.fillStyle = '#ff2244';
      ctx.beginPath(); ctx.arc( 3.5, -HR*1.2, HR, 0, Math.PI*2); ctx.fill();
      ctx.fillStyle = '#00d4ff';
      ctx.beginPath(); ctx.arc(-3.5, -HR*1.2, HR, 0, Math.PI*2); ctx.fill();
      ctx.globalAlpha = 1;
      ctx.restore();
      // Scan-line tears — new random positions every frame → flicker effect
      ctx.save();
      for (var _gsi = 0; _gsi < 5; _gsi++) {
        if (Math.random() < 0.55) {
          var _gsy = -HR*2.4 + Math.random()*(LL*2.2);
          ctx.globalAlpha = 0.5 + Math.random()*0.45;
          ctx.fillStyle   = Math.random() < 0.5 ? '#ff2244' : '#00d4ff';
          ctx.fillRect((Math.random()-0.5)*28 - 4, _gsy, 8+Math.random()*28, 1+Math.random()*0.8);
        }
      }
      ctx.globalAlpha = 1;
      ctx.restore();
    }

    // Hat
    if (state === 'celebrate') {
      ctx.beginPath();
      ctx.moveTo(-5, -HR*1.9); ctx.lineTo(5, -HR*1.9); ctx.lineTo(0, -HR*2.8);
      ctx.closePath(); ctx.fillStyle = '#f0f'; ctx.fill();
      ctx.strokeStyle = '#ff0'; ctx.lineWidth = 1.5;
      ctx.beginPath(); ctx.moveTo(-2.5,-HR*1.9); ctx.lineTo(-1,-HR*2.8); ctx.stroke();
      ctx.beginPath(); ctx.moveTo( 2.5,-HR*1.9); ctx.lineTo( 1,-HR*2.8); ctx.stroke();
    } else if (state === 'ghost') {
      ctx.strokeStyle = '#ff0'; ctx.lineWidth = 2;
      ctx.beginPath(); ctx.ellipse(0, -HR*2.4, 7, 3, 0, 0, Math.PI*2); ctx.stroke();
    } else {
      ctx.beginPath();
      ctx.moveTo(-6,-HR*1.9); ctx.lineTo(6,-HR*1.9); ctx.lineTo(0,-HR*2.3);
      ctx.closePath(); ctx.fillStyle = '#f0a'; ctx.fill();
      ctx.save();
      ctx.translate(0, -HR*2.3);
      ctx.rotate(t * 9); // spin
      ctx.fillStyle = '#ff0'; ctx.beginPath(); ctx.arc(0,0,3,0,Math.PI*2); ctx.fill();
      for (var b = 0; b < 3; b++) {
        ctx.save();
        ctx.rotate(b * Math.PI * 2/3);
        ctx.fillStyle = '#0af';
        ctx.beginPath(); ctx.moveTo(0,0); ctx.lineTo(6,-2); ctx.lineTo(8,-6); ctx.lineTo(2,-3); ctx.closePath(); ctx.fill();
        ctx.restore();
      }
      ctx.restore();
    }

    // Body
    ctx.beginPath();
    ctx.moveTo(0, -HR*0.5); ctx.lineTo(0, LL*0.8);
    ctx.strokeStyle = CFG.bodyColor; ctx.lineWidth = LW; ctx.stroke();

    // Arms
    ctx.strokeStyle = CFG.bodyColor; ctx.lineWidth = LW;
    var armSwing = Math.sin(t * 10) * 0.7;
    var sides = _LIMB_SIDES;
    for (var ai = 0; ai < sides.length; ai++) {
      var side = sides[ai];
      ctx.save();
      ctx.translate(0, -HR * 0.2);
      var angle;
      if      (state === 'panic')      angle = side * (1.5 + Math.sin(t * 22) * 0.6);
      else if (state === 'celebrate')  angle = side * (1.2 + Math.sin(t * 8) * 0.4);
      else if (state === 'philosophy') angle = side === 1 ? -0.3 : 0.3;
      else if (state === 'angry')      angle = side * (2.0 + Math.sin(t * 28) * 0.5);
      else if (state === 'nap')        angle = side * 1.5;
      else if (state === 'dizzy')      angle = side * (1.0 + Math.sin(t * 3 + side) * 0.9);
      else if (state === 'dance')      angle = side * (1.1 + Math.sin(t * 7 + side * Math.PI) * 0.75);
      else if (state === 'stuck')      angle = side * (0.7 + Math.sin(t * 38 + side) * 0.85);
      else if (state === 'sprint')     angle = side * (0.3 + Math.sin(t * 20 + side * Math.PI) * 1.2);
      else                             angle = side * (0.8 + armSwing);
      ctx.rotate(angle);
      ctx.beginPath(); ctx.moveTo(0,0); ctx.lineTo(LL, 0); ctx.stroke();
      ctx.fillStyle = CFG.bodyColor;
      ctx.beginPath(); ctx.arc(LL, 0, 2, 0, Math.PI*2); ctx.fill();
      ctx.restore();
    }

    // Legs
    for (var li = 0; li < sides.length; li++) {
      var lside = sides[li];
      ctx.save();
      ctx.translate(0, LL * 0.8);
      var swing;
      if      (state === 'moonwalk')  swing = lside * (0.5 + Math.sin(t * 10 + lside*1.5) * 0.55);
      else if (state === 'celebrate') swing = lside * (0.3 + Math.abs(Math.sin(t * 6)) * 0.7);
      else if (state === 'panic')     swing = lside * (1.0 + Math.sin(t * 18) * 0.5);
      else if (state === 'trip' || state === 'ghost') swing = lside * 0.8;
      else if (state === 'nap')        swing = lside * 0.25;
      else if (state === 'dizzy')      swing = lside * (0.6 - Math.sin(t * 7) * 0.7);
      else if (state === 'angry')      swing = lside * (0.5 + Math.sin(t * 24 + lside) * 0.9);
      else if (state === 'dance')      swing = lside * (0.4 + Math.sin(t * 8 + lside * 2.2) * 0.85);
      else if (state === 'stuck')      swing = lside * (0.5 + Math.sin(t * 34 + lside) * 0.95);
      else if (state === 'sprint')     swing = lside * (0.4 + Math.sin(t * 22 + lside * 1.8) * 1.25);
      else                            swing = lside * (0.6 - Math.sin(t * 10) * 0.5);
      ctx.rotate(swing);
      ctx.beginPath(); ctx.moveTo(0,0); ctx.lineTo(LL,0); ctx.stroke();
      ctx.fillStyle = CFG.bodyColor;
      ctx.beginPath(); ctx.arc(LL, 0, 2.5, 0, Math.PI*2); ctx.fill();
      ctx.restore();
    }

    // Celebrate sparkles
    if (state === 'celebrate') {
      var colors = _CELEBRATE_COLORS;
      for (var ci = 0; ci < 6; ci++) {
        var ang = ci * Math.PI/3 + t * 4;
        var sr  = 16 + Math.sin(t * 7 + ci) * 4;
        ctx.fillStyle = colors[ci % colors.length];
        ctx.beginPath();
        ctx.arc(Math.cos(ang)*sr, Math.sin(ang)*sr - HR*1.5, 2.5, 0, Math.PI*2);
        ctx.fill();
      }
    }

    // Orbiting stars
    for (var si = 0; si < S.stars.length; si++) {
      var st2 = S.stars[si];
      ctx.fillStyle = '#ff0';
      ctx.beginPath(); ctx.arc(st2.x, st2.y, 2.5, 0, Math.PI*2); ctx.fill();
    }

    // Rage steam from ears
    if (state === 'angry') {
      for (var sp = 0; sp < 4; sp++) {
        var _sage = (t * 1.6 + sp * 0.48) % 1.5;
        var _sAlpha = Math.min(1, _sage * 4) * (1 - _sage / 1.5) * 0.9;
        ctx.globalAlpha = _sAlpha;
        ctx.fillStyle = (sp % 2 === 0) ? '#ff4444' : '#ff8800';
        ctx.beginPath();
        ctx.arc(
          (sp % 2 === 0 ? -HR - 1 : HR + 1) + Math.sin(_sage * 9 + sp) * 2,
          -HR * 2.0 - _sage * 16,
          2.5 + _sage * 2, 0, Math.PI * 2
        );
        ctx.fill();
      }
      ctx.globalAlpha = 1;
    }

    // Floating music notes while dancing
    if (state === 'dance') {
      ctx.textBaseline = 'middle';
      ctx.textAlign    = 'center';
      var _notes = ['\u266A', '\u266B'];
      for (var _ni = 0; _ni < 3; _ni++) {
        var _na  = (t * 1.1 + _ni * 0.6) % 2.2;
        var _nalpha = Math.min(1, _na * 2.0) * (1 - _na / 2.2);
        var _nx  = -14 + _ni * 13 + Math.sin(_na * 4 + _ni) * 4;
        var _ny  = -CFG.headRadius * 2.8 - _na * 14;
        ctx.globalAlpha = _nalpha * 0.92;
        ctx.font        = 'bold ' + (9 + _ni * 2) + 'px sans-serif';
        ctx.fillStyle   = '#ffe066';
        ctx.fillText(_notes[_ni % 2], _nx, _ny);
      }
      ctx.globalAlpha = 1;
      ctx.font = CFG.bubbleFont;
    }

    // Dust puffs while stuck — spread from feet, no upward progress
    if (state === 'stuck') {
      for (var _di = 0; _di < 4; _di++) {
        var _da    = (t * 2.2 + _di * 0.5) % 1.1;
        var _dalpha = Math.min(1, _da * 5) * (1 - _da / 1.1) * 0.5;
        ctx.globalAlpha = _dalpha;
        ctx.fillStyle   = '#cccccc';
        ctx.beginPath();
        ctx.arc(
          (_di % 2 === 0 ? -1 : 1) * (7 + _da * 14) + Math.sin(_da * 9 + _di) * 3,
          CFG.limbLength * 0.9,
          2 + _da * 3.5, 0, Math.PI * 2
        );
        ctx.fill();
      }
      ctx.globalAlpha = 1;
    }

    // Floating Zs while napping – three Z particles at staggered life-cycles
    if (state === 'nap') {
      ctx.textBaseline = 'middle';
      ctx.textAlign    = 'center';
      for (var z = 0; z < 3; z++) {
        var age    = (t * 0.85 + z * 0.6) % 1.9;
        var zAlpha = Math.min(1, age * 2.5) * (1 - age / 1.9);
        var zX     = -11 + z * 7 + Math.sin(age * 3.5) * 3;
        var zY     = -HR * 2.8 - age * 13;
        ctx.globalAlpha = zAlpha * 0.95;
        ctx.font        = 'bold ' + (8 + z * 3) + 'px sans-serif';
        ctx.fillStyle   = '#a8e6ff';
        ctx.fillText('Z', zX, zY);
      }
      ctx.globalAlpha = 1;
      ctx.font = CFG.bubbleFont;
    }

    ctx.restore();
  }

  /* =====================================================================
     Drawing – speech / thought bubble
     ===================================================================== */

  var _supportsRoundRect = null;
  var _textW = (typeof Map === 'function') ? new Map() : null;

  function drawBubble(ctx, x, y, text, isThought) {
    if (!text) return;
    ctx.save();
    ctx.font = CFG.bubbleFont;

    var pad   = CFG.bubblePadding;
    var lineH = 16;
    var maxBW = Math.min(S.W - 16, 210);
    var margin = 6;

    // Word-wrap
    var words = text.split(' '), lines = [], cur = '';
    for (var wi = 0; wi < words.length; wi++) {
      var test = cur ? cur + ' ' + words[wi] : words[wi];
      if (ctx.measureText(test).width > maxBW - pad * 2 && cur) { lines.push(cur); cur = words[wi]; }
      else { cur = test; }
    }
    if (cur) lines.push(cur);

    // Measure widest line (cached)
    var bw = 0;
    for (var li = 0; li < lines.length; li++) {
      var lw;
      if (_textW) {
        lw = _textW.get(lines[li]);
        if (lw === undefined) {
          lw = ctx.measureText(lines[li]).width;
          if (_textW.size > 120) _textW.clear();
          _textW.set(lines[li], lw);
        }
      } else { lw = ctx.measureText(lines[li]).width; }
      if (lw > bw) bw = lw;
    }
    bw += pad * 2;
    var bh = lineH * lines.length + pad * 2;

    var HR  = CFG.headRadius;
    var gap = 22;

    // Horizontal side placement
    var onLeft = x > S.W * 0.55;
    var bx;
    if (onLeft) {
      bx = x - HR - gap - bw;
      if (bx < margin) { onLeft = false; bx = x + HR + gap; }
    } else {
      bx = x + HR + gap;
      if (bx + bw > S.W - margin) { onLeft = true; bx = x - HR - gap - bw; }
    }
    // Hard clamp — guarantee bubble stays on canvas regardless of edge cases
    bx = Math.max(margin, Math.min(S.W - bw - margin, bx));

    // Vertical: centre on walker upper body, never above/below canvas
    var by = (y - HR * 0.8) - bh / 2;
    if (by < margin)              by = margin;
    if (by + bh > S.H - margin)  by = S.H - bh - margin;

    // Tail tip aimed at the walker's head from the bubble's inner edge
    var tailEdgeX = onLeft ? bx + bw : bx;
    var tailTipX  = onLeft ? tailEdgeX + 8 : tailEdgeX - 8;
    var tailY     = y - HR * 0.4;

    // Draw bubble body
    ctx.fillStyle = CFG.bubbleBg;
    if (_supportsRoundRect === null) _supportsRoundRect = typeof ctx.roundRect === 'function';
    ctx.beginPath();
    if (_supportsRoundRect) ctx.roundRect(bx, by, bw, bh, 6);
    else                    ctx.rect(bx, by, bw, bh);
    ctx.fill();

    // Tail: thought-bubble dots arc horizontally toward walker; speech = triangle
    if (isThought) {
      for (var i = 0; i < 3; i++) {
        var r2   = 3 - i;
        var dotX = onLeft ? tailEdgeX + 2 + i * 5 : tailEdgeX - 2 - i * 5;
        var dotY = tailY + i * 2;
        ctx.beginPath(); ctx.arc(dotX, dotY, r2, 0, Math.PI * 2); ctx.fill();
      }
    } else {
      ctx.beginPath();
      if (onLeft) {
        ctx.moveTo(tailEdgeX, tailY - 5);
        ctx.lineTo(tailTipX,  tailY);
        ctx.lineTo(tailEdgeX, tailY + 5);
      } else {
        ctx.moveTo(tailEdgeX, tailY - 5);
        ctx.lineTo(tailTipX,  tailY);
        ctx.lineTo(tailEdgeX, tailY + 5);
      }
      ctx.fill();
    }

    // Text
    ctx.fillStyle = CFG.bubbleColor; ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
    for (var li2 = 0; li2 < lines.length; li2++)
      ctx.fillText(lines[li2], bx + bw / 2, by + pad + lineH * (li2 + 0.5));

    ctx.restore();
  }

  /* =====================================================================
     Drawing – props
     ===================================================================== */
  function drawBanana(ctx, x, y) {
    ctx.save();
    ctx.translate(x, y);
    ctx.rotate(0.4);
    ctx.fillStyle = '#ffe033';
    ctx.beginPath();
    ctx.moveTo(-7, 2); ctx.quadraticCurveTo(0, -12, 7, 2);
    ctx.quadraticCurveTo(4, 5, -4, 5); ctx.closePath();
    ctx.fill();
    ctx.strokeStyle = '#a08000'; ctx.lineWidth = 1;
    ctx.stroke();
    // brown tips
    ctx.fillStyle = '#7a5000';
    ctx.beginPath(); ctx.arc(-7, 2, 2.5, 0, Math.PI*2); ctx.fill();
    ctx.beginPath(); ctx.arc( 7, 2, 2.5, 0, Math.PI*2); ctx.fill();
    ctx.restore();
  }

  function drawAlienBeam(ctx, x, y, alpha) {
    ctx.save();
    ctx.globalAlpha = alpha;
    var bw = 55 * alpha;
    // tractor beam
    ctx.fillStyle = 'rgba(0,255,180,0.35)';
    ctx.beginPath();
    ctx.moveTo(x - bw/2, y);
    ctx.lineTo(x + bw/2, y);
    ctx.lineTo(x + bw*0.18, y - 76);
    ctx.lineTo(x - bw*0.18, y - 76);
    ctx.closePath(); ctx.fill();
    // UFO body
    ctx.fillStyle = '#bbb';
    ctx.beginPath(); ctx.ellipse(x, y-80, 22, 8, 0, 0, Math.PI*2); ctx.fill();
    // dome
    ctx.fillStyle = '#0f8';
    ctx.beginPath(); ctx.ellipse(x, y-86, 11, 7, 0, 0, Math.PI*2); ctx.fill();
    // running lights
    var lights = _ALIEN_LIGHTS;
    for (var i = 0; i < 4; i++) {
      var lx = x - 14 + i * 9;
      ctx.fillStyle = lights[i];
      ctx.beginPath(); ctx.arc(lx, y-80, 2, 0, Math.PI*2); ctx.fill();
    }
    ctx.restore();
  }

  function drawSpeedLines(ctx, x, y, speed) {
    if (speed < 60) return;
    var maxA = Math.min(1, (speed - 60) / 20) * 0.28;
    var sign  = S.state === 'moonwalk' ? 1 : -1;
    ctx.save();
    ctx.strokeStyle = '#ffffff';
    ctx.lineCap     = 'round';
    for (var i = 0; i < 5; i++) {
      ctx.globalAlpha = maxA * (1 - i * 0.15);
      ctx.lineWidth   = 1.2 - i * 0.14;
      var ly  = y - 16 + i * 8;
      var len = 13 + i * 5;
      ctx.beginPath();
      ctx.moveTo(x + sign * 6, ly);
      ctx.lineTo(x + sign * (6 + len), ly);
      ctx.stroke();
    }
    ctx.globalAlpha = 1;
    ctx.restore();
  }

  /* =====================================================================
     Progress event
     ===================================================================== */
  function onProgress(ev) {
    if (!ev || !ev.detail) return;
    var d = ev.detail;
    var total = Number(d.total) || 0, checked = Number(d.checked) || 0;
    S.errors  = Number(d.errors) || 0;
    S.pct     = total > 0 ? Math.round((checked / total) * 100) : (d.status === 'done' ? 100 : S.pct);
    var wasRunning = S.running;
    S.running = d.status === 'running';

    if (!wasRunning && S.running) {
      S.state = 'walk'; S.stateTimer = 0;
      S.speechText = ''; S.speechTimer = 0; S.nextSpeech = 2;
      S.lastMilestone = 0;
    }
    // Reset speed on ANY non-running state (stale log, done, interrupted, idle)
    if (!S.running && d.status !== 'done') S.pct = 0;
    if (wasRunning && !S.running && d.status === 'done') {
      S.state = 'celebrate'; S.stateTimer = 0;
      say(pick(CFG.celebrateSpeech), 4, true);
    }
    // Guard: transition into panic only once per error burst — not on every tick
    if (S.running && S.errors > 0 && S.state !== 'panic' && S.state !== 'angry') {
      S.state = 'panic'; S.stateTimer = 0;
      say(pick(CFG.panicSpeech), 2, true);
    }
    // Milestone speech at 25 / 50 / 75 % — fires once each, force overrides guard
    if (S.running) {
      var ms = S.pct >= 75 ? 75 : S.pct >= 50 ? 50 : S.pct >= 25 ? 25 : 0;
      if (ms && ms > S.lastMilestone) {
        S.lastMilestone = ms;
        var bank = ms === 75 ? CFG.milestone75 : ms === 50 ? CFG.milestone50 : CFG.milestone25;
        say(pick(bank), 4, true);
      }
    }
  }

  /* =====================================================================
     State machine update  (called each frame)
     ===================================================================== */
  function updateState(dt) {
    var st = S.state;

    // Detect walk re-entry: add a brief pause before next idle quip
    if (st === 'walk' && S.prevState !== 'walk' && S.nextSpeech <= 0)
      S.nextSpeech = 1.0 + Math.random() * 0.8;
    S.prevState = st;

    // Tick state timers – only while NOT in walk (walk is the resting state)
    if (st !== 'walk') S.stateTimer += dt;
    if (st === 'angry') S.angerTime += dt;

    // Transition guards
    if (st === 'panic'      && S.stateTimer > 1.5) { S.state = 'walk'; S.stateTimer = 0; S.speechText = ''; }
    if (st === 'celebrate'  && S.stateTimer > 5.0) { S.state = 'walk'; S.stateTimer = 0; S.speechText = ''; }
    if (st === 'sneeze'     && S.stateTimer > 1.2) { S.state = 'walk'; S.stateTimer = 0; }
    if (st === 'philosophy' && S.stateTimer > 3.5) { S.state = 'walk'; S.stateTimer = 0; S.speechText = ''; }
    if (st === 'moonwalk'   && S.stateTimer > 3.0) { S.state = 'walk'; S.stateTimer = 0; }
    if (st === 'ghost'      && S.stateTimer > 4.5) { S.state = 'walk'; S.stateTimer = 0; S.speechText = ''; }
    if (st === 'nap'        && S.stateTimer > 2.8) { S.state = 'walk'; S.stateTimer = 0; say(pick(CFG.napWakeSpeech), 1.8); }
    if (st === 'dizzy'      && S.stateTimer > 2.2) { S.state = 'walk'; S.stateTimer = 0; }
    if (st === 'angry'      && S.stateTimer > 2.5) { S.state = 'walk'; S.stateTimer = 0; S.speechText = ''; S.angerTime = 0; }
    if (st === 'dance'      && S.stateTimer > 3.5) { S.state = 'walk'; S.stateTimer = 0; }
    if (st === 'stuck'      && S.stateTimer > 2.4) { S.state = 'walk'; S.stateTimer = 0; }
    if (st === 'sprint'     && S.stateTimer > 1.8) { S.state = 'walk'; S.stateTimer = 0; }
    if (st === 'glitch'     && S.stateTimer > 1.5) { S.state = 'walk'; S.stateTimer = 0; }
    if (st === 'cors'       && S.stateTimer > 1.5) { S.state = 'walk'; S.stateTimer = 0; }
    if (st === 'timeout'    && S.stateTimer > 3.0) { S.state = 'walk'; S.stateTimer = 0; S.speechText = ''; }
    if (st === 'deflate'    && S.stateTimer > 2.2) { S.state = 'walk'; S.stateTimer = 0; }
    if (st === 'trip') {
      S.tripAngle *= 0.88;
      if (S.stateTimer > 0.9) { S.state = 'walk'; S.stateTimer = 0; }
    }

    // orbiting stars
    if (S.stars.length) {
      S.starTimer -= dt;
      for (var si = 0; si < S.stars.length; si++) {
        var star = S.stars[si];
        star.angle += dt * 5.5;
        star.x = Math.cos(star.angle) * 13;
        star.y = Math.sin(star.angle) * 9 - CFG.headRadius * 1.6;
      }
      if (S.starTimer <= 0) S.stars = [];
    }

    // alien beam fade
    S.alienBeam = st === 'ghost'
      ? Math.min(1, S.alienBeam + dt * 2)
      : Math.max(0, S.alienBeam - dt * 3);

    // cursor proximity
    var _mdx = S.mouseX - S.walkerX, _mdy = S.mouseY - S.curY;
    S.mouseNear     = (_mdx * _mdx + _mdy * _mdy) < 3600;
    S.mouseOnWalkie = Math.abs(_mdx) < 22 && Math.abs(_mdy) < 42;

    if (S.mouseNear && st === 'walk') {
      var _etx = Math.max(-1.8, Math.min(1.8, _mdx * 0.04));
      var _ety = Math.max(-1.0, Math.min(1.0, _mdy * 0.04));
      S.eyeOffsetX += (_etx - S.eyeOffsetX) * Math.min(1, dt * 14);
      S.eyeOffsetY += (_ety - S.eyeOffsetY) * Math.min(1, dt * 14);

      S.shyTimer += dt;
      if (S.shyTimer > 1.5 && S._then - S.lastMouseSpeechAt > CFG.mouseSpeechCooldownMs) {
        say(pick(CFG.shySpeech), 2.0);
        S.lastMouseSpeechAt = S._then;
        S.shyTimer = 0;
      }
    } else {
      S.eyeOffsetX += (0 - S.eyeOffsetX) * Math.min(1, dt * 7);
      S.eyeOffsetY += (0 - S.eyeOffsetY) * Math.min(1, dt * 7);
      if (!S.mouseNear) S.shyTimer = 0;
    }

    // banana prop
    S.bananaTimer -= dt;
    if (S.bananaX > -500) {
      S.bananaAge += dt;
      if (S.bananaAge > 11 || S.bananaX < S.walkerX - S.W * 0.55) {
        S.bananaX = -9999; S.bananaAge = 0; S.bananaTimer = 20 + Math.random() * 28;
      }
    }
    if (S.bananaTimer <= 0 && S.bananaX < -500) {
      S.bananaX = S.walkerX + 90 + Math.random() * 130;
      S.bananaAge = 0;
      S.bananaTimer = 32 + Math.random() * 28;
    }
    // step-on detection
    if (S.bananaX > -500 && st === 'walk' && Math.abs(S.walkerX - S.bananaX) < 14) {
      S.bananaX     = -9999;
      S.bananaTimer = 18 + Math.random() * 22;
      S.state = 'trip'; S.stateTimer = 0; S.tripAngle = 1.1;
      S.stars = (function() {
        var arr = [];
        for (var i = 0; i < 5; i++) {
          var _a = i * Math.PI*2/5;
          arr.push({ angle: _a, x: Math.cos(_a)*13, y: Math.sin(_a)*9 - CFG.headRadius*1.6 });
        }
        return arr;
      }());
      S.starTimer = 2.2;
      say(pick(CFG.bananaSpeech), 2.5, true);
      return;
    }

    // random events (walk only)
    if (st === 'walk') {
      var _sc = Math.min(2.0, dt * 60);
      var r = Math.random();
      if      (r < 0.000303 * _sc) { S.state='sneeze';     S.stateTimer=0; say(pick(CFG.sneezeSpeech), 2); }
      else if (r < 0.000525 * _sc) { S.state='moonwalk';   S.stateTimer=0; say(pick(CFG.moonwalkSpeech), 2.5); }
      else if (r < 0.000782 * _sc) { S.state='philosophy'; S.stateTimer=0; say(pick(CFG.philosophySpeech), 3.5); }
      else if (r < 0.000910 * _sc) { S.state='ghost';      S.stateTimer=0; S.alienBeam=0; say(pick(CFG.alienSpeech), 3.5); }
      else if (r < 0.001148 * _sc) { S.state='nap';        S.stateTimer=0; say(pick(CFG.napSpeech), 2.5); }
      else if (r < 0.001451 * _sc) { S.state='dizzy';      S.stateTimer=0; say(pick(CFG.dizzyQuips), 1.8); }
      else if (r < 0.001618 * _sc) { S.state='trip'; S.stateTimer=0; S.tripAngle=0.55; S.stars=(function(){var a=[];for(var _i=0;_i<3;_i++){var _sa=_i*Math.PI*2/3;a.push({angle:_sa,x:Math.cos(_sa)*13,y:Math.sin(_sa)*9-CFG.headRadius*1.6});}return a;}()); S.starTimer=1.5; say('NPP',1.4); }
      else if (r < 0.001951 * _sc) { S.state='dance';      S.stateTimer=0; say(pick(CFG.danceSpeech)); }
      else if (r < 0.002189 * _sc) { S.state='stuck';      S.stateTimer=0; say(pick(CFG.stuckSpeech)); }
      else if (r < 0.002360 * _sc) { S.state='sprint';     S.stateTimer=0; say(pick(CFG.sprintSpeech)); }
      else if (r < 0.002799 * _sc) { say(pick(CFG.aiEgoSpeech)); }
      else if (r < 0.003037 * _sc) { S.state='glitch';     S.stateTimer=0; say(pick(CFG.glitchSpeech), 1.5); }
      else if (r < 0.003222 * _sc) { S.state='cors';       S.stateTimer=0; say(pick(CFG.corsSpeech), null, true); }
      else if (r < 0.003430 * _sc) { S.state='timeout';    S.stateTimer=0; say(pick(CFG.timeoutSpeech)); }
      else if (r < 0.003640 * _sc) { S.state='deflate';    S.stateTimer=0; say(pick(CFG.deflateSpeech)); }
    }
  }

  /* =====================================================================
     Animation loop
     ===================================================================== */
  function draw(nowMs) {
    if (S.disposed) return;
    if (!S.inView || document.hidden) { requestAnimationFrame(draw); return; }
    if (!document.body.contains(S.host)) { dispose(); return; }

    var dt = Math.min(0.05, (nowMs - (S._then || nowMs)) / 1000);
    S._then = nowMs;
    S.animTime += dt;
    var t    = S.animTime;
    var tSec = nowMs / 1000;

    var ctx = S.ctx;
    ctx.clearRect(0, 0, S.W, S.H);

    // Speed based on progress; FLEE when the cursor lands on walkie
    S.speed = CFG.baseSpeed + (CFG.maxSpeed - CFG.baseSpeed) * (S.pct / 100);
    if (S.mouseOnWalkie && S.state === 'walk')
      S.speed = Math.min(CFG.maxSpeed * 1.5, S.speed * 2.2);
    if (S.state === 'ghost')   S.speed *= 0.22;
    if (S.state === 'sprint')  S.speed  = CFG.maxSpeed * 2.4;
    if (S.state === 'timeout') S.speed *= 0.06;

    // Move walker (moonwalk = reverse; nap/stuck/cors = stationary)
    var dir = S.state === 'moonwalk' ? -1 : 1;
    if (S.state !== 'nap' && S.state !== 'stuck' && S.state !== 'cors') S.walkerX += S.speed * dt * dir;
    if (S.walkerX >  S.W + 30) S.walkerX = -30;
    if (S.walkerX < -30)        S.walkerX =  S.W + 30;

    var yBase = ribbonY(S.walkerX, tSec);

    // Dynamic top margin per state
    var _tgt = 29, bottomMargin = 22;
    switch (S.state) {
      case 'celebrate': _tgt = 50; break;
      case 'dance':     _tgt = 60; break;
      case 'nap':       _tgt = 52; break;
      case 'angry':     _tgt = 45; break;
      case 'sprint':    _tgt = 34; break;
      case 'glitch':    _tgt = 36; break;
      case 'timeout':   _tgt = 58; break;
      case 'cors':      _tgt = 50; break;
      case 'ghost':     _tgt = 29 + 61 * S.alienBeam; break;
    }
    S._topM += (_tgt - S._topM) * Math.min(1, dt * 10);
    yBase = Math.max(S._topM | 0, Math.min(S.H - bottomMargin, yBase));
    S.curY = yBase;

    // Update state machine
    updateState(dt);

    // Speech tick
    if (S.speechTimer > 0) {
      S.speechTimer -= dt;
      if (S.speechTimer <= 0) S.speechText = '';
    }
    if (S.state === 'walk' && !S.speechText) {
      if (S.nextSpeech <= 0) {
        S.nextSpeech = CFG.speechIntervalMin + Math.random() * (CFG.speechIntervalMax - CFG.speechIntervalMin);
        say(pick(CFG.questions));
      } else {
        S.nextSpeech -= dt;
      }
    }

    // render
    if (S.bananaX > -500) {
      var _bananaY = ribbonY(S.bananaX, tSec);
      _bananaY = Math.max(29, Math.min(S.H - 22, _bananaY));
      drawBanana(ctx, S.bananaX, _bananaY);
    }
    drawSpeedLines(ctx, S.walkerX, yBase, S.speed);
    if (S.alienBeam > 0) drawAlienBeam(ctx, S.walkerX, yBase, S.alienBeam);

    if (S.speechText) drawBubble(ctx, S.walkerX, yBase, S.speechText, S.state === 'philosophy');
    drawCharacter(ctx, S.walkerX, yBase, t, S.state);

    // CORS wall
    if (S.state === 'cors') {
      var _cwA = Math.max(0, 1 - S.stateTimer / 1.5) * 0.92;
      if (_cwA > 0.01) {
        var _cwx = S.walkerX + 20;
        ctx.save();

        var _cwg = ctx.createLinearGradient(_cwx-5, 0, _cwx+5, 0);
        _cwg.addColorStop(0,   'rgba(255,60,60,0)');
        _cwg.addColorStop(0.4, 'rgba(255,60,60,' + (_cwA*0.9).toFixed(3) + ')');
        _cwg.addColorStop(0.6, 'rgba(255,90,50,' + (_cwA*0.9).toFixed(3) + ')');
        _cwg.addColorStop(1,   'rgba(255,60,60,0)');
        ctx.fillStyle = _cwg;
        ctx.fillRect(_cwx-5, yBase-34, 10, 58);

        ctx.globalAlpha = _cwA;
        ctx.strokeStyle = '#ff6040'; ctx.lineWidth = 2.8; ctx.lineCap = 'round';
        var _cwcy = yBase - 16;
        ctx.beginPath(); ctx.moveTo(_cwx-7, _cwcy-7); ctx.lineTo(_cwx+7, _cwcy+7); ctx.stroke();
        ctx.beginPath(); ctx.moveTo(_cwx+7, _cwcy-7); ctx.lineTo(_cwx-7, _cwcy+7); ctx.stroke();

        var _rp = S.stateTimer * 3.5;
        if (_rp < Math.PI*2) {
          var _rr = Math.max(0, Math.sin(_rp)) * 15;
          ctx.globalAlpha = Math.max(0, 0.5 * (1 - _rp/(Math.PI*2))) * _cwA;
          ctx.strokeStyle = '#ff5050'; ctx.lineWidth = 1.5;
          ctx.beginPath(); ctx.arc(_cwx, yBase-14, _rr, 0, Math.PI*2); ctx.stroke();
        }
        ctx.restore();
      }
    }

    // Timeout spinner + "504" label
    if (S.state === 'timeout') {
      var _toFade = Math.min(1, S.stateTimer*2.5) * Math.max(0, 1-(S.stateTimer-2.2)/0.8);
      if (_toFade > 0.01) {
        var _toX = S.walkerX, _toY = yBase - CFG.headRadius*2.8 - 14;
        var _toR = 9;
        var _toSpin = S.stateTimer * (2.8 + S.stateTimer*0.6);
        ctx.save();
        ctx.globalAlpha = _toFade;

        ctx.strokeStyle = 'rgba(255,200,0,0.22)';
        ctx.lineWidth = 2.5;
        ctx.beginPath(); ctx.arc(_toX, _toY, _toR, 0, Math.PI*2); ctx.stroke();
        // Active spinner arc
        ctx.strokeStyle = '#ffcc00'; ctx.lineWidth = 2.5; ctx.lineCap = 'round';
        ctx.beginPath(); ctx.arc(_toX, _toY, _toR, _toSpin, _toSpin + Math.PI*1.4); ctx.stroke();
        // Leading dot for snappiness
        ctx.fillStyle = '#ffcc00';
        ctx.beginPath(); ctx.arc(
          _toX + Math.cos(_toSpin + Math.PI*1.4) * _toR,
          _toY + Math.sin(_toSpin + Math.PI*1.4) * _toR,
          2, 0, Math.PI*2
        ); ctx.fill();

        ctx.fillStyle = '#ffcc00';
        ctx.font = 'bold 9px monospace';
        ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
        ctx.fillText('504', _toX, _toY - _toR - 9);
        ctx.restore();
      }
    }

    // Deflate gzip badge
    if (S.state === 'deflate' && S.stateTimer > 0.5 && S.stateTimer < 2.1) {
      var _dbA = Math.min(1, (S.stateTimer-0.5)*2.5) * Math.max(0, 1-(S.stateTimer-1.6)/0.5);
      if (_dbA > 0.01) {
        ctx.save();
        ctx.globalAlpha = _dbA;
        ctx.font = 'bold 10px monospace';
        ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
        ctx.fillStyle = '#80ff80';
        ctx.fillText('gzip \u2713', S.walkerX, yBase - CFG.headRadius*2.8 - 10);
        ctx.restore();
      }
    }

    requestAnimationFrame(draw);
  }

  /* =====================================================================
     Bootstrap & teardown
     ===================================================================== */
  function onMouseMove(ev) {
    var r = S.host.getBoundingClientRect();
    S.mouseX = ev.clientX - r.left;
    S.mouseY = ev.clientY - r.top;
  }
  function onMouseLeave() {
    S.mouseX = -9999; S.mouseY = -9999;
    S.shyTimer = 0; S.eyeOffsetX = 0; S.eyeOffsetY = 0;
  }
  function onMouseClick() {
    if (!S.mouseOnWalkie) return;
    if (S.state === 'nap') {
      say(pick(CFG.napWakeSpeech), 2); S.state = 'walk'; S.stateTimer = 0;
    } else if (S.state === 'celebrate') {
      say('I WAS IN THE MIDDLE OF MY VICTORY SPEECH!!! \uD83D\uDE24');
    } else if (S.state === 'ghost') {
      say('I\'M ASTRAL PROJECTING! THAT IS SO RUDE \uD83D\uDC7B');
    } else if (S.state === 'angry') {
      if (S.angerTime < 7) S.stateTimer = 0;
      say(pick(CFG.angrySpeech), null, true);
    } else {
      S.state = 'angry'; S.stateTimer = 0; S.angerTime = 0; say(pick(CFG.angrySpeech), null, true);
    }
  }
  function onMouseRightClick(ev) {
    if (!S.mouseOnWalkie) return;
    ev.preventDefault();
    say('I see you, hacker \uD83D\uDC40  I have logged your IP.', null, true);
  }

  function start() {
    S.host = pickHost();
    if (!S.host) return;
    if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches)
      CFG.baseSpeed *= 0.5;

    S.canvas = ensureCanvas(S.host);
    S.ctx    = S.canvas.getContext('2d', { alpha: true });
    S.DPR    = Math.max(1, Math.min(2, window.devicePixelRatio || 1));
    resize();

    window.addEventListener('resize', resize, { passive: true });
    window.addEventListener('nppp:preload-progress', onProgress);

    S.host.addEventListener('mousemove',   onMouseMove,     { passive: true });
    S.host.addEventListener('mouseleave',  onMouseLeave);
    S.host.addEventListener('click',       onMouseClick);
    S.host.addEventListener('contextmenu', onMouseRightClick);

    var io = new IntersectionObserver(function(entries) {
      S.inView = !!(entries[0] && entries[0].isIntersecting);
    });
    io.observe(S.host); S._io = io;

    var ro = new ResizeObserver(function() {
      var r = S.host.getBoundingClientRect();
      var W = Math.max(320, r.width|0), H = Math.max(80, r.height|0);
      var d = Math.max(1, Math.min(2, window.devicePixelRatio || 1));
      if (W === S.W && H === S.H && d === S.DPR) return;
      S.DPR = d; resize();
    });
    ro.observe(S.host); S._ro = ro;

    // First banana spawns 10–30 s in so the user can find their feet first
    S.bananaTimer = 10 + Math.random() * 20;

    requestAnimationFrame(draw);
  }

  function dispose() {
    S.disposed = true;
    window.removeEventListener('resize', resize);
    window.removeEventListener('nppp:preload-progress', onProgress);
    try { if (S.host) {
      S.host.removeEventListener('mousemove',   onMouseMove);
      S.host.removeEventListener('mouseleave',  onMouseLeave);
      S.host.removeEventListener('click',       onMouseClick);
      S.host.removeEventListener('contextmenu', onMouseRightClick);
    }} catch (_) {}
    try { if (S._io) S._io.disconnect(); } catch (_) {}
    try { if (S._ro) S._ro.disconnect(); } catch (_) {}
    try { if (S.canvas) S.canvas.remove(); } catch (_) {}
    delete window.NPPPAurora.__walkiePatched;
  }
  window.NPPPAuroraWalkieDispose = dispose;

  if (document.readyState === 'loading')
    document.addEventListener('DOMContentLoaded', start, { once: true });
  else
    start();
})();
