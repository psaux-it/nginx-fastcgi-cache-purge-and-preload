/**
 * JavaScript for Aurora Canvas Header Effect
 * Description: Animated aurora-like ribbon effect with AJAX pulse reactions for Nginx Cache Purge Preload for Wordpress
 * Version: 2.1.3
 * Author: Hasan CALISIR
 * Author Email: hasan.calisir@psauxit.com
 * Author URI: https://www.psauxit.com
 * License: GPL-2.0+
 */

(function(){
  'use strict';

  const CFG = {
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
    ajaxPulseHueBias: 25
  };

  function start(){
    const host = document.querySelector('.wrap .nppp-header-content') || document.querySelector('.nppp-header-content');
    if(!host) return;
    if(getComputedStyle(host).position==='static') host.style.position='relative';

    let canvas = host.querySelector('.nppp-aurora-canvas');
    if(!canvas){
      canvas = document.createElement('canvas');
      canvas.className = 'nppp-aurora-canvas';
      host.appendChild(canvas);
    }
    const ctx = canvas.getContext('2d', { alpha:true });

    let W=0, H=0, DPR=Math.max(1, Math.min(2, window.devicePixelRatio||1));
    let then = performance.now()/1000;
    let reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    // ---------- Simplex Noise ----------
    // Stefan Gustavson 2D Simplex Noise port (minimize)
    const G2 = (3.0 - Math.sqrt(3.0))/6.0;
    const F2 = 0.5*(Math.sqrt(3.0)-1.0);
    const perm = new Uint8Array(512);
    (function buildPerm(){
      const p = new Uint8Array(256);
      for(let i=0;i<256;i++) p[i]=i;
      let n, q;
      for(let i=255;i>0;i--){ n=(Math.random()*(i+1))|0; q=p[i]; p[i]=p[n]; p[n]=q; }
      for(let i=0;i<512;i++) perm[i]=p[i&255];
    })();
    function dot(gx,gy,x,y){ return gx*x+gy*y; }
    function noise2D(xin,yin){
      const s = (xin+yin)*F2;
      const i = Math.floor(xin+s);
      const j = Math.floor(yin+s);
      const t = (i+j)*G2;
      const X0 = i - t, Y0 = j - t;
      const x0 = xin - X0, y0 = yin - Y0;
      let i1, j1;
      if (x0>y0){ i1=1; j1=0; } else { i1=0; j1=1; }
      const x1 = x0 - i1 + G2;
      const y1 = y0 - j1 + G2;
      const x2 = x0 - 1 + 2*G2;
      const y2 = y0 - 1 + 2*G2;
      const ii = i & 255, jj = j & 255;

      function gi(a,b){
        const gi = perm[a+perm[b]] % 12;
        // gradients: (±1,0),(0,±1),(±1,±1) norm approx
        const gx = [1,-1,1,-1, 1,-1, 0, 0, 1,-1, 1,-1][gi];
        const gy = [0, 0,1, 1,-1,-1, 1,-1, 1, 1,-1,-1][gi];
        return [gx, gy];
      }
      let n0,n1,n2, t0,t1,t2, g;

      t0 = 0.5 - x0*x0 - y0*y0;
      if (t0<0) n0=0; else { g=gi(ii,jj); t0*=t0; n0 = t0*t0*dot(g[0],g[1],x0,y0); }

      t1 = 0.5 - x1*x1 - y1*y1;
      if (t1<0) n1=0; else { g=gi(ii+i1,jj+j1); t1*=t1; n1 = t1*t1*dot(g[0],g[1],x1,y1); }

      t2 = 0.5 - x2*x2 - y2*y2;
      if (t2<0) n2=0; else { g=gi(ii+1,jj+1); t2*=t2; n2 = t2*t2*dot(g[0],g[1],x2,y2); }

      return 70*(n0+n1+n2);
    }

    // ---------- Aurora Ribbons ----------
    const ribbons = [];
    function resetRibbons(){
      ribbons.length = 0;
      const L = liveRect();
      for(let i=0;i<CFG.ribbons;i++){
        const y = L.y + (L.h * (0.2 + 0.6*Math.random()));
        const hue = CFG.hueStops[i % CFG.hueStops.length];
        ribbons.push({
          y, baseY: y,
          x: L.x + Math.random()*L.w,
          hue,
          amp: 1,
          seed: Math.random()*10000
        });
      }
    }

    function liveRect(){
      const r = host.getBoundingClientRect();
      const tail = Math.min(Math.max(160, r.width*0.25), 320);
      return { x: -tail*0.25, y: 0, w: r.width + tail, h: r.height, tail };
    }

    function resize(){
      const r = host.getBoundingClientRect();
      const w = Math.max(320, r.width|0);
      const h = Math.max(80,  r.height|0);
      W=w; H=h;
      const wPx = (w*DPR)|0, hPx=(h*DPR)|0;
      if (canvas.width!==wPx) canvas.width=wPx;
      if (canvas.height!==hPx) canvas.height=hPx;
      canvas.style.width = w+'px';
      canvas.style.height = h+'px';
      ctx.setTransform(DPR,0,0,DPR,0,0);
      resetRibbons();
    }

    function hsla(h,s,l,a){ return `hsla(${h},${s}%,${l}%,${a})`; }

    // Autopulse
    let nextPulse = 0;
    function schedulePulse(nowS){
      const [a,b] = CFG.pulseEvery;
      nextPulse = nowS + (a + Math.random()*(b-a));
    }
    function pulse(amp=1, hueBias=0){
      for(const r of ribbons){
        r.amp = Math.min(2.0, r.amp * (1 + 0.25*amp));
        r.hue = (r.hue + hueBias + 360) % 360;
      }
    }

    // Draw
    function draw(nowS){
      const dt = Math.min(0.05, nowS - then); then = nowS;

      ctx.globalCompositeOperation = 'source-over';
      ctx.fillStyle = `rgba(10,12,16,${CFG.trail})`;
      ctx.fillRect(0,0,W,H);

      const L = liveRect();
      const t = nowS;

      for(const r of ribbons){
        const pathPoints = 120;
        const thickness = CFG.thickness * (0.8 + 0.4*Math.sin(t*0.7 + r.seed));
        const hue = r.hue;
        const sat = CFG.sat;
        const lig = CFG.light;

        // Y wave
        ctx.beginPath();
        for(let i=0;i<=pathPoints;i++){
          const u = i / pathPoints;
          const x = L.x + u * L.w;
          const n = noise2D(
            (x + r.seed) * CFG.noiseScale,
            (r.baseY + t*1000*CFG.speed) * CFG.noiseScale
          );
          const y = r.baseY + (n * 60 * r.amp);
          if(i===0) ctx.moveTo(x,y);
          else ctx.lineTo(x,y);
        }

        // stroke + light glow
        const grad = ctx.createLinearGradient(L.x, r.baseY, L.x + L.w, r.baseY);
        grad.addColorStop(0.00, hsla(hue, sat, lig-6, CFG.alpha*0.55));
        grad.addColorStop(0.45, hsla(hue, sat, lig,   CFG.alpha));
        grad.addColorStop(0.85, hsla(hue, sat, lig-6, CFG.alpha*0.50));
        grad.addColorStop(1.00, hsla(hue, sat, lig-10, 0));

        ctx.strokeStyle = grad;
        ctx.lineWidth = thickness;
        ctx.lineCap = 'round';
        ctx.shadowColor = hsla(hue, sat, lig, 0.45);
        ctx.shadowBlur = 10;
        ctx.stroke();

        r.amp = Math.max(1, r.amp - dt*0.25);
        // x’i biraz kaydır (akış hissi)
        r.x += (Math.sin(t*0.6 + r.seed)*0.5);
      }

      // Autopulse
      if (!reduce && nowS >= nextPulse){
        pulse(1.0, 8);
        schedulePulse(nowS);
      }

      requestAnimationFrame(ts => draw(ts/1000));
    }

    // DPR sync
    function syncDPR(){
      const d = Math.max(1, Math.min(2, window.devicePixelRatio||1));
      if(d!==DPR){ DPR=d; resize(); }
    }

    // Events
    window.addEventListener('resize', resize, {passive:true});
    setInterval(syncDPR, 1000);

    // Start
    resize();
    const t0 = performance.now()/1000;
    schedulePulse(t0);
    requestAnimationFrame(ts => { then = ts/1000; draw(ts/1000); });

    // -------- Public API (AJAX) --------
    const API = {
      pulse(amp=1, hueBias=0){ pulse(amp, hueBias); },
      networkStart(){ pulse(0.6, 6); },
      networkEnd(ok=true){ pulse(ok ? 0.8 : 1.1, ok ? 12 : -25); }
    };
    if(!window.NPPPAurora) window.NPPPAurora = API;

    // Auto Hooks (jQuery / fetch / XHR)
    if (window.jQuery && !window.__NPPP_AJAX_JQ_HOOKED2){
      window.__NPPP_AJAX_JQ_HOOKED2 = true;
      jQuery(document).on('ajaxSend', ()=> API.networkStart());
      jQuery(document).on('ajaxComplete', (e, xhr)=> API.networkEnd(xhr ? (xhr.status>=200 && xhr.status<400) : true));
      jQuery(document).on('ajaxError', ()=> API.networkEnd(false));
    }
    if (window.fetch && !window.__NPPP_AJAX_FETCH_HOOKED2){
      window.__NPPP_AJAX_FETCH_HOOKED2 = true;
      const _fetch = window.fetch;
      window.fetch = function(){
        try{ API.networkStart(); }catch(e){}
        return _fetch.apply(this, arguments)
          .then(res=>{ try{ API.networkEnd(res.ok); }catch(e){} return res; })
          .catch(err=>{ try{ API.networkEnd(false); }catch(e){} throw err; });
      };
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
  }

  if(document.readyState==='loading') document.addEventListener('DOMContentLoaded', start, {once:true});
  else start();
})();
