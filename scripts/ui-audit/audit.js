/*
 * UI audit harness. Self-contained: defines window.__uiAudit(), which returns a JSON-serialisable report.
 * Load it with a script tag (the app CSP blocks eval): copy it to public/ui-audit.js, then in the page run
 *   await new Promise(r => { const s = document.createElement("script"); s.src = "/ui-audit.js"; s.onload = r; document.head.append(s); }); JSON.stringify(__uiAudit())
 * See docs/UI-STANDARD.md for how to drive it at 375x812, 768x1024 and 1280x800.
 *
 * Rules reported (counts plus up to 8 samples each):
 *   overflow-x, overlap, small-text, contrast, touch-target, no-label, img-alt, glow (box-shadow, text-shadow,
 *   filter, backdrop-filter, gradient, pulse/ping animation), clipped-text, covered-by-fixed, table-escape, h1.
 * A "critical" rule is any of: overflow-x, overlap, small-text, contrast, touch-target (<1024px), no-label,
 * img-alt, glow, covered-by-fixed, table-escape, h1.
 */
window.__uiAudit = () => {
  const W = window.innerWidth, H = window.innerHeight;
  const out = { url: location.pathname + location.search, width: W, height: H, counts: {}, samples: {} };
  const add = (rule, el, extra) => {
    out.counts[rule] = (out.counts[rule] || 0) + 1;
    const s = (out.samples[rule] = out.samples[rule] || []);
    if (s.length < 8) s.push(Object.assign({ el: describe(el) }, extra || {}));
  };
  function describe(el) {
    if (!el || !el.tagName) return String(el);
    let d = el.tagName.toLowerCase();
    if (el.id) d += '#' + el.id;
    const c = (el.getAttribute('class') || '').trim().split(/\s+/).slice(0, 3).join('.');
    if (c) d += '.' + c;
    const t = (el.innerText || el.getAttribute('aria-label') || '').trim().replace(/\s+/g, ' ').slice(0, 30);
    return t ? d + ' "' + t + '"' : d;
  }
  const cs = (el) => getComputedStyle(el);
  const visible = (el) => {
    const r = el.getBoundingClientRect();
    if (r.width < 1 || r.height < 1) return false;
    for (let e = el; e && e !== document.documentElement; e = e.parentElement) {
      const s = cs(e);
      if (s.display === 'none' || s.visibility === 'hidden' || s.opacity === '0') return false;
      if (e.hasAttribute && e.hasAttribute('hidden')) return false;
      if (/sr-only/.test(e.getAttribute('class') || '') || (s.clip === 'rect(0px, 0px, 0px, 0px)' && s.position === 'absolute')) return false;
    }
    return true;
  };
  const all = Array.from(document.body.querySelectorAll('*')).filter((e) => !/^(SCRIPT|STYLE|NOSCRIPT|LINK|META|PATH|CIRCLE|LINE|RECT|POLYGON|POLYLINE|G|DEFS|TITLE)$/i.test(e.tagName) || e.tagName === 'svg');
  const vis = new Map();
  const isVis = (e) => { if (!vis.has(e)) vis.set(e, visible(e)); return vis.get(e); };

  // ---- colour helpers
  const parse = (str) => {
    const m = str.match(/rgba?\(([^)]+)\)/);
    if (!m) return null;
    const p = m[1].split(/[,\s/]+/).filter(Boolean).map(Number);
    return { r: p[0], g: p[1], b: p[2], a: p.length > 3 ? p[3] : 1 };
  };
  const over = (top, bot) => {
    const a = top.a + bot.a * (1 - top.a);
    if (a === 0) return { r: 0, g: 0, b: 0, a: 0 };
    return { r: (top.r * top.a + bot.r * bot.a * (1 - top.a)) / a, g: (top.g * top.a + bot.g * bot.a * (1 - top.a)) / a, b: (top.b * top.a + bot.b * bot.a * (1 - top.a)) / a, a };
  };
  const lum = (c) => { const f = (v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); }; return 0.2126 * f(c.r) + 0.7152 * f(c.g) + 0.0722 * f(c.b); };
  const ratio = (a, b) => { const x = lum(a), y = lum(b); return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05); };
  /** Effective background: composite every ancestor background (alpha aware) over white. Returns null when an image or gradient is involved. */
  const effBg = (el) => {
    const layers = [];
    for (let e = el; e; e = e.parentElement) {
      const s = cs(e);
      if (s.backgroundImage && s.backgroundImage !== 'none') return null;
      const c = parse(s.backgroundColor);
      if (c && c.a > 0) { layers.push(c); if (c.a >= 1) break; }
    }
    let acc = { r: 255, g: 255, b: 255, a: 1 };
    for (let i = layers.length - 1; i >= 0; i--) acc = over(layers[i], acc);
    return acc;
  };

  // ---- document level
  if (document.documentElement.scrollWidth > W + 1) add('overflow-x', document.documentElement, { scrollWidth: document.documentElement.scrollWidth, innerWidth: W });
  const main = document.getElementById('main') || document.querySelector('main');
  if (main && main.scrollWidth > main.clientWidth + 1) add('overflow-x', main, { scrollWidth: main.scrollWidth, clientWidth: main.clientWidth });
  const h1s = Array.from(document.querySelectorAll('h1')).filter(isVis);
  if (h1s.length !== 1) add('h1', document.body, { count: h1s.length });
  if (!document.querySelector('main, [role=main]')) add('landmark', document.body, { missing: 'main' });
  if (!document.title) add('title', document.body);

  // ---- per element
  const interactiveSel = 'a[href],button,input:not([type=hidden]),select,textarea,summary,[role=button],[role=tab],[role=link],[role=menuitem],[tabindex]:not([tabindex="-1"])';
  /** The element rectangle clipped by scrolling or overflow-hidden ancestors, or null when nothing of it is visible. */
  const visRect = (e) => {
    const b = e.getBoundingClientRect();
    let l = b.left, t = b.top, r = b.right, bt = b.bottom;
    for (let p = e.parentElement; p && p !== document.documentElement; p = p.parentElement) {
      const o = cs(p);
      if (o.overflowX === "visible" && o.overflowY === "visible") continue;
      const pr = p.getBoundingClientRect();
      if (o.overflowX !== "visible") { l = Math.max(l, pr.left); r = Math.min(r, pr.right); }
      if (o.overflowY !== "visible") { t = Math.max(t, pr.top); bt = Math.min(bt, pr.bottom); }
      if (r - l < 1 || bt - t < 1) return null;
    }
    return { left: l, top: t, right: r, bottom: bt };
  };
  const interactive = all.filter((e) => e.matches(interactiveSel) && isVis(e) && !!visRect(e) && !(e.tagName.toLowerCase() === "svg" && !e.getAttribute("role")));
  const touch = W < 1024;

  for (const e of all) {
    const s = cs(e);
    // glow
    if (s.boxShadow && s.boxShadow !== 'none') add('glow', e, { boxShadow: s.boxShadow.slice(0, 60) });
    if (s.textShadow && s.textShadow !== 'none') add('glow', e, { textShadow: s.textShadow.slice(0, 60) });
    if (s.filter && s.filter !== 'none') add('glow', e, { filter: s.filter.slice(0, 60) });
    if (s.backdropFilter && s.backdropFilter !== 'none') add('glow', e, { backdropFilter: s.backdropFilter.slice(0, 60) });
    if (/gradient/.test(s.backgroundImage)) add('glow', e, { gradient: s.backgroundImage.slice(0, 60) });
    if (s.animationName && /pulse|ping/.test(s.animationName)) add('glow', e, { animation: s.animationName });
  }

  // images
  for (const img of document.querySelectorAll('img')) if (isVis(img) && !img.hasAttribute('alt')) add('img-alt', img);
  for (const sv of document.querySelectorAll('svg[role=img]')) if (isVis(sv) && !sv.getAttribute('aria-label') && !sv.querySelector('title')) add('img-alt', sv);

  // labels
  const nameOf = (e) => {
    if (e.getAttribute('aria-label')) return e.getAttribute('aria-label').trim();
    const lb = e.getAttribute('aria-labelledby');
    if (lb) return lb.split(/\s+/).map((id) => (document.getElementById(id) || {}).textContent || '').join(' ').trim();
    if (e.labels && e.labels.length) return Array.from(e.labels).map((l) => l.textContent).join(' ').trim();
    if (e.tagName === 'INPUT' && /^(button|submit|reset)$/.test(e.type)) return e.value || e.title || '';
    const t = (e.innerText || e.textContent || '').trim();
    if (t) return t;
    const im = e.querySelector('img[alt]'); if (im && im.alt.trim()) return im.alt.trim();
    return (e.getAttribute('title') || e.getAttribute('placeholder') || '').trim();
  };
  for (const e of interactive) {
    if (e.tagName === 'INPUT' && /^(checkbox|radio)$/.test(e.type) && !nameOf(e)) { add('no-label', e); continue; }
    if (!nameOf(e) && !(e.tagName === 'INPUT' && e.type === 'file')) add('no-label', e);
    else if (/^(INPUT|SELECT|TEXTAREA)$/.test(e.tagName) && !(e.getAttribute('aria-label') || e.getAttribute('aria-labelledby') || (e.labels && e.labels.length) || e.title)) add('no-label', e, { note: 'placeholder only' });
  }

  // touch targets (coarse widths only)
  if (touch) {
    for (const e of interactive) {
      const s = cs(e);
      if (s.display === 'inline' && e.tagName === 'A') continue; // inline link inside text: exempt
      if (e.tagName === 'INPUT' && /^(checkbox|radio)$/.test(e.type)) { const lb = e.closest('label'); const r2 = (lb || e).getBoundingClientRect(); if (r2.height >= 44 || r2.width >= 44) continue; }
      const r = e.getBoundingClientRect();
      if (r.width < 43.5 || r.height < 43.5) add('touch-target', e, { w: Math.round(r.width), h: Math.round(r.height) });
    }
  }

  // overlapping interactive elements
  const fixedAncestor = (e) => { for (let a = e; a && a !== document.body; a = a.parentElement) { const p = cs(a).position; if (p === "fixed" || p === "sticky") return a; } return null; };
  const rects = interactive.map((e) => [e, visRect(e)]);
  for (let i = 0; i < rects.length; i++) {
    for (let j = i + 1; j < rects.length; j++) {
      const [a, ra] = rects[i], [b, rb] = rects[j];
      if (a.contains(b) || b.contains(a)) continue;
      if (!!fixedAncestor(a) !== !!fixedAncestor(b)) continue;
      const ix = Math.min(ra.right, rb.right) - Math.max(ra.left, rb.left);
      const iy = Math.min(ra.bottom, rb.bottom) - Math.max(ra.top, rb.top);
      if (ix > 4 && iy > 4) {
        const ca = a.closest('label'), cb = b.closest('label');
        if (ca && ca === cb) continue;
        add('overlap', a, { with: describe(b), px: Math.round(ix) + 'x' + Math.round(iy) });
      }
    }
  }

  // text rules
  const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
  const seen = new Set();
  let n;
  while ((n = walker.nextNode())) {
    const txt = n.nodeValue.trim();
    const p = n.parentElement;
    if (!txt || !p || seen.has(p) || /^(SCRIPT|STYLE|NOSCRIPT|OPTION)$/.test(p.tagName) || !isVis(p)) continue;
    const dis = p.closest("[disabled],[aria-disabled=true]"); // disabled controls are exempt from contrast (WCAG 1.4.3)
    seen.add(p);
    const s = cs(p);
    const size = parseFloat(s.fontSize);
    if (size < 13) add('small-text', p, { px: size });
    // clipped text
    if ((s.overflow === 'hidden' || s.overflowX === 'hidden') && s.textOverflow !== 'ellipsis' && p.scrollWidth > p.clientWidth + 2 && s.whiteSpace === 'nowrap') add('clipped-text', p, { sw: p.scrollWidth, cw: p.clientWidth });
    // contrast
    const bg = effBg(p);
    const fg = parse(s.color);
    if (bg && fg && !dis) {
      const top = over({ r: fg.r, g: fg.g, b: fg.b, a: fg.a * (parseFloat(s.opacity) || 1) }, bg);
      const r = ratio(top, bg);
      const bold = parseInt(s.fontWeight, 10) >= 700;
      const large = size >= 24 || (size >= 18.66 && bold);
      if (r < (large ? 3 : 4.5)) add('contrast', p, { ratio: Math.round(r * 100) / 100, fg: s.color, bg: 'rgb(' + [bg.r, bg.g, bg.b].map(Math.round).join(',') + ')' });
    }
  }

  // tables
  for (const t of document.querySelectorAll('table')) {
    if (!isVis(t)) continue;
    let sc = null;
    for (let e = t.parentElement; e && e !== document.body; e = e.parentElement) {
      const o = cs(e).overflowX;
      if (o === 'auto' || o === 'scroll') { sc = e; break; }
    }
    const r = t.getBoundingClientRect();
    if (!sc && r.right > W + 1) add('table-escape', t, { right: Math.round(r.right), innerWidth: W });
    if (!sc) { const par = t.parentElement; if (par && t.scrollWidth > par.clientWidth + 2 && cs(par).overflowX === 'visible' && r.right > par.getBoundingClientRect().right + 2) add('table-escape', t, { note: 'wider than parent, no scroller' }); }
  }

  // fixed or sticky elements covering interactive content: checked with the main pane scrolled to its end, so only content that can never be scrolled clear is reported
  const pane = main && main.scrollHeight > main.clientHeight ? main : null;
  const pane0 = pane ? pane.scrollTop : 0;
  if (pane) pane.scrollTop = pane.scrollHeight;
  for (const e of interactive) {
    const r = e.getBoundingClientRect();
    if (r.bottom < 0 || r.top > H || r.right < 0 || r.left > W) continue;
    const cx = Math.min(Math.max(r.left + r.width / 2, 0), W - 1), cy = Math.min(Math.max(r.top + r.height / 2, 0), H - 1);
    const top = document.elementFromPoint(cx, cy);
    if (!top || e.contains(top) || top.contains(e)) continue;
    for (let a = top; a && a !== document.body; a = a.parentElement) {
      const pos = cs(a).position;
      if (pos === 'fixed' || pos === 'sticky') {
        const inBar = a.closest('header,nav,aside,[role=dialog],.overlay');
        // elements scrolled under a sticky app bar or bottom bar are normal scrolling; flag only when the covered element sits in the resting layout
        if (!inBar || !a.contains(e)) add('covered-by-fixed', e, { by: describe(a) });
        break;
      }
    }
  }

  if (pane) pane.scrollTop = pane0;

  // summary
  const critical = ['overflow-x', 'overlap', 'small-text', 'contrast', 'no-label', 'img-alt', 'glow', 'covered-by-fixed', 'table-escape', 'h1'];
  if (touch) critical.push('touch-target');
  out.critical = critical.reduce((s, k) => s + (out.counts[k] || 0), 0);
  return out;
};
