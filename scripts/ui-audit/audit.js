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
  const _cv = document.createElement('canvas').getContext('2d', { willReadFrequently: true });
  const _cache = {};
  /** Modern colour syntaxes (oklch, lab, color-mix...) are normalised to rgba through a 1x1 canvas. */
  const norm = (str) => {
    if (!str || /^rgba?\(/.test(str) || str === 'transparent') return str;
    if (_cache[str]) return _cache[str];
    try { _cv.clearRect(0, 0, 1, 1); _cv.fillStyle = '#000'; _cv.fillStyle = str; _cv.fillRect(0, 0, 1, 1); const d = _cv.getImageData(0, 0, 1, 1).data; return (_cache[str] = 'rgba(' + d[0] + ',' + d[1] + ',' + d[2] + ',' + d[3] / 255 + ')'); } catch (e) { return str; }
  };
  const parse = (str) => {
    str = norm(str);
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
      if (e.tagName === 'INPUT' && /^(checkbox|radio)$/.test(e.type)) { const lb = e.closest('label'); const r2 = (lb || e).getBoundingClientRect(); if (r2.height >= 44 || r2.width >= 44) continue; const ps = getComputedStyle(e, '::after'); if (ps.content !== 'none' && parseFloat(ps.width) >= 44 && parseFloat(ps.height) >= 44) continue; }
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
        const adorn = (x, y) => x.tagName === 'INPUT' && y.parentElement === x.parentElement && cs(y).position === 'absolute';
        if (adorn(a, b) || adorn(b, a)) continue; // reveal or clear button drawn inside its input by design
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
    const dis = p.closest("[disabled],[aria-disabled=true]") || (() => { for (let e = p; e && e !== document.body; e = e.parentElement) { const c = cs(e); if (c.pointerEvents === "none" && parseFloat(c.opacity) < 1) return e; } return null; })(); // disabled controls are exempt from contrast (WCAG 1.4.3)
    seen.add(p);
    const s = cs(p);
    const size = parseFloat(s.fontSize);
    // [data-doc-preview] is a scaled picture of a printed document (content, aria-hidden), not UI text
    if (size < 13 && !p.closest('[data-doc-preview]')) add('small-text', p, { px: size });
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
  const docEl = document.scrollingElement || document.documentElement;
  const win0 = docEl.scrollTop;
  if (!pane && docEl.scrollHeight > H + 1) docEl.scrollTop = docEl.scrollHeight; // layouts where the window is the scroller (member portal, public pages)
  if (pane) pane.scrollTop = pane.scrollHeight;
  for (const e of interactive) {
    const r = e.getBoundingClientRect();
    if (r.bottom < 0 || r.top > H || r.right < 0 || r.left > W) continue;
    const cx = Math.min(Math.max(r.left + r.width / 2, 0), W - 1), cy = Math.min(Math.max(r.top + r.height / 2, 0), H - 1);
    const top = document.elementFromPoint(cx, cy);
    if (!top || e.contains(top) || top.contains(e)) continue;
    for (let a = top; a && a !== document.body; a = a.parentElement) {
      const pos = cs(a).position;
      if (pos === 'sticky' && cs(a).top !== 'auto' && cs(a).bottom === 'auto') break; // top-sticky bar: content scrolls clear of it
      if (pos === 'fixed' || pos === 'sticky') {
        const inBar = a.closest('header,nav,aside,[role=dialog],.overlay');
        // elements scrolled under a sticky app bar or bottom bar are normal scrolling; flag only when the covered element sits in the resting layout
        if (!inBar || !a.contains(e)) add('covered-by-fixed', e, { by: describe(a) });
        break;
      }
    }
  }

  if (pane) pane.scrollTop = pane0;
  docEl.scrollTop = win0;

  // ================= EXTENDED RULES (R1, directive of 2026-11-04). Advisory unless noted in docs/UI-AUDIT-FINAL.md =================
  const FALLBACK_OK = /^(lucide|recharts)/;
  // images: rendered ratio versus natural ratio (distortion), circles that are not circles
  for (const img of document.querySelectorAll('img')) {
    if (!isVis(img) || !img.naturalWidth || !img.naturalHeight) continue;
    const r = img.getBoundingClientRect();
    const fit = cs(img).objectFit;
    const nat = img.naturalWidth / img.naturalHeight, ren = r.width / r.height;
    if ((fit === 'fill' || fit === '') && Math.abs(ren - nat) / nat > 0.06) add('img-distort', img, { natural: nat.toFixed(2), rendered: ren.toFixed(2) });
  }
  for (const c of document.querySelectorAll('canvas')) {
    if (!isVis(c) || !c.width || !c.height) continue;
    const r = c.getBoundingClientRect();
    if (Math.abs(r.width / r.height - c.width / c.height) / (c.width / c.height) > 0.06) add('img-distort', c, { canvas: (c.width / c.height).toFixed(2), rendered: (r.width / r.height).toFixed(2) });
  }
  for (const sv of document.querySelectorAll('svg[preserveAspectRatio=none]')) if (isVis(sv) && !sv.closest('.recharts-wrapper')) add('img-distort', sv, { note: 'preserveAspectRatio=none' });
  for (const e of all) {
    const s = cs(e);
    if (!isVis(e) || e.tagName === 'svg') continue;
    const br = parseFloat(s.borderTopLeftRadius);
    const r = e.getBoundingClientRect();
    if (r.width >= 16 && r.width <= 160 && (br >= r.width / 2 - 1 || /50%|9999|3.4e/.test(s.borderTopLeftRadius)) && (e.tagName === 'IMG' || /avatar/i.test(e.getAttribute('class') || '')) && Math.abs(r.width - r.height) > 2 && r.height >= 16) add('img-distort', e, { note: 'circle is an ellipse', w: Math.round(r.width), h: Math.round(r.height) });
  }
  // line length on wide screens: characters per rendered line
  if (W >= 1024) {
    const blocks = Array.from(document.querySelectorAll('p,li,dd,blockquote,td,div,span')).filter((e) => isVis(e) && !e.closest('table') && !/svg/i.test(e.tagName));
    for (const e of blocks) {
      const own = Array.from(e.childNodes).filter((x) => x.nodeType === 3).map((x) => x.nodeValue).join('').replace(/\s+/g, ' ').trim();
      if (own.length < 120) continue;
      const s = cs(e);
      const lh = parseFloat(s.lineHeight) || parseFloat(s.fontSize) * 1.5;
      const lines = Math.max(1, Math.round(e.getBoundingClientRect().height / lh));
      const cpl = own.length / lines;
      if (lines >= 2 ? cpl > 90 : e.getBoundingClientRect().width > 90 * parseFloat(s.fontSize) * 0.5 && own.length > 100) add('long-line', e, { cpl: Math.round(cpl), w: Math.round(e.getBoundingClientRect().width) });
    }
  }
  // wide screens: stretched controls and content
  if (W >= 1600) {
    for (const e of document.querySelectorAll('.input,.select,.textarea,input[type=text],input[type=email],input[type=search],input[type=tel],input[type=number],select,textarea')) {
      if (!isVis(e) || e.closest('.table-wrap,table')) continue;
      const w = e.getBoundingClientRect().width;
      if (w > 720) add('stretched', e, { kind: 'control', w: Math.round(w) });
    }
    for (const f of document.querySelectorAll('form')) { if (isVis(f) && !f.closest('[role=dialog]') && f.getBoundingClientRect().width > 1100 && f.querySelectorAll('.input,.select,input,select').length >= 2 && !f.querySelector('table')) add('stretched', f, { kind: 'form', w: Math.round(f.getBoundingClientRect().width) }); }
    for (const t of document.querySelectorAll('table')) {
      if (!isVis(t)) continue;
      const cols = t.querySelectorAll('thead th').length || 1, w = t.getBoundingClientRect().width;
      if (cols <= 5 && w / cols > 380) add('stretched', t, { kind: 'table', cols, w: Math.round(w) });
    }
  }
  if (main) { let mw = 0; for (const c of main.children) mw = Math.max(mw, c.getBoundingClientRect().width); out.metrics = Object.assign(out.metrics || {}, { contentW: Math.round(mw) }); }
  // charts: overlapping tick labels / legend text inside one svg
  for (const sv of document.querySelectorAll('svg.recharts-surface, svg[role=img]')) {
    if (!isVis(sv)) continue;
    const txt = Array.from(sv.querySelectorAll('text')).filter((t) => t.textContent.trim() && t.getBoundingClientRect().width > 0).map((t) => [t, t.getBoundingClientRect()]);
    let hit = 0, first = null;
    for (let i = 0; i < txt.length; i++) for (let j = i + 1; j < txt.length; j++) {
      const a = txt[i][1], b = txt[j][1];
      const ix = Math.min(a.right, b.right) - Math.max(a.left, b.left), iy = Math.min(a.bottom, b.bottom) - Math.max(a.top, b.top);
      if (ix > 2 && iy > 3) { hit++; first = first || txt[i][0].textContent.trim() + ' / ' + txt[j][0].textContent.trim(); }
    }
    if (hit) add('chart-overlap', sv, { pairs: hit, first });
    const sr = sv.getBoundingClientRect();
    if (sr.width >= 60 && sr.height >= 60 && W < 480 && sr.width < 240) add('chart-overlap', sv, { note: 'chart narrower than 240px on phone', w: Math.round(sr.width) });
  }
  // typography
  const phone = W < 768;
  for (const h of h1s) {
    const fs = parseFloat(cs(h).fontSize);
    out.metrics = Object.assign(out.metrics || {}, { h1: fs });
    if (phone ? (fs < 22 || fs > 30) : (fs < 24 || fs > 36)) add('h1-size', h, { px: fs });
  }
  let upperBlocks = 0;
  const tw2 = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
  const seen2 = new Set();
  let n2;
  while ((n2 = tw2.nextNode())) {
    const p = n2.parentElement, t = n2.nodeValue.replace(/\s+/g, ' ').trim();
    if (!p || !t || seen2.has(p) || !isVis(p) || /^(SCRIPT|STYLE|OPTION|TEXTAREA)$/.test(p.tagName)) continue;
    seen2.add(p);
    const s = cs(p), fs = parseFloat(s.fontSize);
    const isUpper = s.textTransform === 'uppercase' || (t.length > 12 && t === t.toUpperCase() && /[A-Z]{4}/.test(t));
    if (isUpper && t.length > 28) { upperBlocks++; add('uppercase-block', p, { len: t.length }); }
    if (t.length >= 60 && fs < (phone ? 14 : 13)) add('body-small', p, { px: fs, len: t.length });
    if (phone && t.length >= 80 && fs < 16 && fs >= 14) out.counts['body-lt16-phone'] = (out.counts['body-lt16-phone'] || 0) + 1;
    // money and numbers without tabular figures
    if ((/^[-+−]?\d[\d\s .,]*\s?(FCFA|XAF|XOF|EUR|USD|F|€|\$|%)?$/.test(t) && t.replace(/\D/g, '').length >= 4) && !/tabular-nums/.test(s.fontVariantNumeric || '') && !p.closest('h1,h2,h3,time') ) add('no-tabular', p, { text: t.slice(0, 20) });
  }
  // icons: size, family, name
  for (const sv of document.querySelectorAll('svg')) {
    if (!isVis(sv) || sv.closest('.recharts-wrapper,[data-doc-preview],canvas,.l1-chart-legend,.l1-chart-tip')) continue;
    const r = sv.getBoundingClientRect();
    if (r.width > 40 || r.height > 40) continue;
    const cls = sv.getAttribute('class') || '';
    if (!/lucide/.test(cls) && !sv.closest('[data-brand-logo],.brand-logo')) add('icon-family', sv, { in: describe(sv.parentElement) });
    else if (/lucide/.test(cls)) {
      const sw = parseFloat(cs(sv).strokeWidth);
      if (sw && (sw < 1.5 || sw > 2.25)) add('icon-stroke', sv, { sw });
      if (cs(sv).fill !== 'none' && cs(sv).fill !== 'rgba(0, 0, 0, 0)') add('icon-family', sv, { note: 'filled lucide' });
    }
  }
  // colour-only status: tiny coloured dots with no text neighbour and no name
  for (const e of all) {
    if (e.tagName === 'svg' || !isVis(e) || e.children.length) continue;
    const r = e.getBoundingClientRect(), s = cs(e);
    if (r.width >= 6 && r.width <= 14 && Math.abs(r.width - r.height) < 1 && parseFloat(s.borderTopLeftRadius) >= r.width / 2 - 0.5 && s.backgroundColor !== 'rgba(0, 0, 0, 0)' && !(e.innerText || '').trim()) {
      const par = e.parentElement; const sib = par ? (par.innerText || '').trim() : '';
      if (!sib && !e.getAttribute('aria-label') && !e.getAttribute('title') && !par.getAttribute('aria-label') && !par.getAttribute('title')) add('colour-only-status', e, { bg: s.backgroundColor });
    }
  }
  // buttons: deviation from the shared classes (computed height, radius, padding)
  const btnSel = '.btn-primary,.btn-secondary,.btn-danger,.btn-ghost,.btn-accent';
  const sig = {};
  for (const b of document.querySelectorAll(btnSel)) {
    if (!isVis(b)) continue;
    const s = cs(b), r = b.getBoundingClientRect();
    if (b.classList.contains('btn-icon') || b.classList.contains('hit-44') || r.width < 20) continue;
    const k = (b.className.match(/btn-(primary|secondary|danger|ghost|accent)/) || [])[0] + (b.classList.contains('btn-sm') ? ' sm' : b.classList.contains('btn-lg') ? ' lg' : '');
    if (r.height > 52) continue; // label wrapped onto two lines: not a style variant
    (sig[k] = sig[k] || {})[Math.round(r.height) + '|' + s.borderTopLeftRadius + '|' + s.paddingLeft + '|' + s.fontSize] = 1;
  }
  out.metrics = Object.assign(out.metrics || {}, { btn: Object.fromEntries(Object.entries(sig).map(([k, v]) => [k, Object.keys(v)])) });
  for (const [k, v] of Object.entries(sig)) if (Object.keys(v).length > 1 && !/ sm| lg/.test(' ' + k.split(' ').slice(1).join(' '))) add('btn-variant', main || document.body, { class: k, variants: Object.keys(v).slice(0, 4) });
  for (const b of document.querySelectorAll('button,a[role=button],a[href]')) {
    if (!isVis(b) || b.matches(btnSel) || b.closest('nav,aside,header,[role=tablist],.tabs,table') ) continue;
    const c = b.getAttribute('class') || '';
    if (/btn|nav-|tab|badge|chip|pill|hit-44|skip|link/.test(c)) continue;
    const s = cs(b), r = b.getBoundingClientRect();
    if (s.backgroundColor !== 'rgba(0, 0, 0, 0)' && parseFloat(s.borderTopLeftRadius) > 0 && r.width >= 56 && r.height >= 24 && r.height <= 64 && (b.innerText || '').trim().length > 1) add('btn-nonstandard', b, { h: Math.round(r.height), r: s.borderTopLeftRadius, bg: s.backgroundColor });
  }
  // cards: nested cards (card inside card)
  for (const c of document.querySelectorAll('.card')) if (isVis(c) && c.parentElement && c.parentElement.closest('.card')) add('nested-card', c);
  // off-token radius on bordered boxes (not 0, 4, 8, 12, 16, 24 or full)
  const okR = new Set(['0px', '2px', '4px', '6px', '8px', '12px', '16px', '24px']);
  const badR = {};
  for (const e of all) { if (!isVis(e) || e.tagName === 'svg') continue; const rr = cs(e).borderTopLeftRadius; if (rr !== '0px' && !okR.has(rr) && parseFloat(rr) < 100 && rr !== '50%') badR[rr] = (badR[rr] || 0) + 1; }
  for (const [k, v] of Object.entries(badR)) add('radius-off-scale', document.body, { radius: k, n: v });
  out.metrics = Object.assign(out.metrics || {}, { fontSizes: Array.from(new Set(Array.from(seen2).map((p) => cs(p).fontSize))).sort((a, b) => parseFloat(a) - parseFloat(b)), zoom: window.devicePixelRatio });

  // summary
  const critical = ['overflow-x', 'overlap', 'small-text', 'contrast', 'no-label', 'img-alt', 'glow', 'covered-by-fixed', 'table-escape', 'h1'];
  if (touch) critical.push('touch-target');
  out.critical = critical.reduce((s, k) => s + (out.counts[k] || 0), 0);
  const advisory = ['img-distort', 'long-line', 'stretched', 'chart-overlap', 'h1-size', 'uppercase-block', 'body-small', 'no-tabular', 'icon-family', 'icon-stroke', 'colour-only-status', 'btn-variant', 'btn-nonstandard', 'nested-card', 'radius-off-scale'];
  out.advisory = advisory.reduce((s, k) => s + (out.counts[k] || 0), 0);
  return out;
};
