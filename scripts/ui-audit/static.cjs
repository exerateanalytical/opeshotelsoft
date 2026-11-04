/*
 * Static analysis of resources/js and resources/css (workstream R1). Writes:
 *   docs/UI-CONSISTENCY-MATRIX.md  (component variants and non-conforming usage, with file lists)
 *   docs/UI-TOKENS.md              (token inventory, hard-coded colours/sizes/radii/z-index outside tokens, counts per file)
 * Usage: node scripts/ui-audit/static.cjs
 * Regex based, so counts are indicative (class strings built dynamically are seen only when written out as literals).
 */
const fs = require('fs');
const path = require('path');
const ROOT = path.join(__dirname, '..', '..');
const JS = path.join(ROOT, 'resources', 'js');
const rel = (f) => path.relative(ROOT, f).replace(/\\/g, '/');
function walk(d, out = []) { for (const e of fs.readdirSync(d, { withFileTypes: true })) { const p = path.join(d, e.name); if (e.isDirectory()) walk(p, out); else if (/\.(tsx|ts)$/.test(e.name)) out.push(p); } return out; }
const files = walk(JS).filter((f) => f.endsWith('.tsx'));
const src = Object.fromEntries(files.map((f) => [rel(f), fs.readFileSync(f, 'utf8')]));
const css = fs.readFileSync(path.join(ROOT, 'resources/css/app.css'), 'utf8');
const isShared = (f) => /resources\/js\/(Components\/(ui|layout)|Layouts)\//.test(f);

/** count matches of re in every file; returns {total, byFile:[[file,n]]} */
function scan(re, { only, skip } = {}) {
  const byFile = [];
  let total = 0;
  for (const [f, s] of Object.entries(src)) {
    if (only && !only(f)) continue;
    if (skip && skip(f)) continue;
    const m = s.match(re);
    if (m && m.length) { byFile.push([f, m.length]); total += m.length; }
  }
  byFile.sort((a, b) => b[1] - a[1]);
  return { total, byFile };
}
const top = (r, n = 8) => r.byFile.slice(0, n).map(([f, c]) => '`' + f.replace('resources/js/', '') + '` ' + c).join('; ') || 'none';
const tally = (re, group = 1) => { const t = {}; for (const s of Object.values(src)) for (const m of s.matchAll(re)) t[m[group]] = (t[m[group]] || 0) + 1; return t; };
const fmt = (t, n = 14) => Object.entries(t).sort((a, b) => b[1] - a[1]).slice(0, n).map(([k, v]) => k + ' ' + v).join(', ');

// literal className strings (and cn(...) literals) for class-level analysis
const classLits = [];
for (const [f, s] of Object.entries(src)) for (const m of s.matchAll(/(?:className|class)=(?:"([^"]*)"|\{`([^`]*)`\}|\{'([^']*)'\})|cn\(([^)]*)\)/g)) classLits.push([f, m[1] || m[2] || m[3] || m[4] || '']);
const lit = (re, filt) => { const byFile = {}; let total = 0; for (const [f, c] of classLits) if (re.test(c) && (!filt || filt(f, c))) { byFile[f] = (byFile[f] || 0) + 1; total++; } return { total, byFile: Object.entries(byFile).sort((a, b) => b[1] - a[1]) }; };

// ---------- buttons
const btnClass = tally(/\b(btn-(?:primary|secondary|danger|ghost|accent))\b/g);
const btnSizes = tally(/\b(btn-(?:sm|lg|icon))\b/g);
const compBtn = scan(/<Button\b/g);
const rawBtn = (() => { const byFile = []; let total = 0; for (const [f, s] of Object.entries(src)) { if (isShared(f) && /Components\/ui\/Button/.test(f)) continue; let n = 0; for (const m of s.matchAll(/<button\b[^>]*?>/gs)) { if (!/btn|nav-item|tab\b|hit-44|\.tab|aria-selected|role="tab"/.test(m[0]) ) n++; } if (n) { byFile.push([f, n]); total += n; } } byFile.sort((a, b) => b[1] - a[1]); return { total, byFile }; })();
const btnOverride = lit(/\bbtn-(primary|secondary|danger|ghost|accent)\b/, (f, c) => /\b(bg-|rounded-|h-\d|py-|px-|text-(xs|lg|xl))/.test(c.replace(/hover:|disabled:|sm:|md:|lg:/g, 'x:')));
const btnPill = lit(/\bbtn-[a-z]+\b/, (f, c) => /rounded-full/.test(c));

// ---------- inputs
const ctl = (() => { const out = { input: { total: 0, ok: 0, byFile: {} }, select: { total: 0, ok: 0, byFile: {} }, textarea: { total: 0, ok: 0, byFile: {} } }; for (const [f, s] of Object.entries(src)) { for (const m of s.matchAll(/<(input|select|textarea)\b([^>]*?)(\/?>)/gs)) { const [, tag, attrs] = m; if (tag === 'input' && /type=["'{](checkbox|radio|hidden|file|range|color)/.test(attrs)) continue; out[tag].total++; if (new RegExp('\\b' + tag + '\\b').test((attrs.match(/className=(?:"[^"]*"|\{[^}]*\})/) || [''])[0]) || /\{\.\.\.(props|rest)\}/.test(attrs) || /className=\{cn\(/.test(attrs)) out[tag].ok++; else out[tag].byFile[f] = (out[tag].byFile[f] || 0) + 1; } } return out; })();
const ctlTop = (k) => Object.entries(ctl[k].byFile).sort((a, b) => b[1] - a[1]).slice(0, 8).map(([f, c]) => '`' + f.replace('resources/js/', '') + '` ' + c).join('; ') || 'none';
const selAsInput = scan(/<select[^>]*className="[^"]*input/gs), taAsInput = scan(/<textarea[^>]*className="[^"]*input/gs);
const fieldComp = scan(/<Field\b/g), labelCls = lit(/\blabel\b/);

// ---------- cards
const cardCls = lit(/(^|\s)card(\s|$)/);
const adhocCard = lit(/rounded-(lg|xl|2xl)/, (f, c) => /\bborder\b/.test(c) && /bg-white/.test(c) && !/\bcard\b/.test(c) && !isShared(f));
const statTile = lit(/stat-tile/);
// ---------- tabs
const tabsComp = scan(/<Tabs\b/g), tabCls = lit(/(^|\s)tabs?(\s|$)/), adhocTabs = scan(/role="tab"/g, { skip: isShared });
// ---------- tables
const tbl = (() => { let raw = 0, wrap = 0; const byFile = []; for (const [f, s] of Object.entries(src)) { const n = (s.match(/<table\b/g) || []).length; if (!n) continue; const w = (s.match(/table-wrap|table-auto|table-std|table-cards/g) || []).length; if (isShared(f)) continue; raw += n; wrap += Math.min(n, w); if (w < n) byFile.push([f, n - w]); } return { raw, wrap, byFile: byFile.sort((a, b) => b[1] - a[1]) }; })();
const dataTable = scan(/<DataTable\b/g);
const cardsTbl = scan(/table-cards/g);
// ---------- modals
const modalComp = scan(/<(Modal|Sheet)\b/g, { skip: isShared });
const roleDialog = scan(/role="dialog"|role='dialog'/g, { skip: (f) => /Components\/ui\/Modal/.test(f) });
const fixedInset = scan(/fixed inset-0/g);
// ---------- badges, alerts
const badgeCls = tally(/\b(badge-(?:green|yellow|red|blue|slate|navy|gold))\b/g);
const adhocBadge = lit(/rounded-full/, (f, c) => /\bpx-(1|1\.5|2|2\.5)\b/.test(c) && /bg-(red|green|emerald|amber|yellow|blue|slate|gray|orange|navy|brand)-(50|100|200)/.test(c) && !/badge/.test(c));
const adhocAlert = lit(/bg-(red|amber|yellow|emerald|green|blue|orange)-(50|100)/, (f, c) => /\bborder/.test(c) && /\brounded/.test(c) && /\bp-(2|3|4)\b|\bpx-\d/.test(c) && !/badge|btn|input|select|card/.test(c) && !isShared(f));
const roleAlert = scan(/role="alert"|role="status"/g);
// ---------- typography
const sizes = tally(/\btext-(xs|sm|base|lg|xl|2xl|3xl|4xl|5xl|6xl)\b/g);
const arbText = scan(/\btext-\[[0-9.]+(px|rem|em)\]/g);
const upper = scan(/\buppercase\b/g);
const tracking = tally(/\b(tracking-(?:tighter|tight|wide|wider|widest))\b/g);
const weights = tally(/\b(font-(?:thin|light|normal|medium|semibold|bold|extrabold|black))\b/g);
const truncs = tally(/\b(truncate|line-clamp-\d|break-words|break-all)\b/g);
const tabular = scan(/tabular-nums/g), mono = scan(/\bfont-mono\b/g);
// ---------- icons
const iconSrc = tally(/from ['"]((?:lucide-react|react-icons[^'"]*|@heroicons[^'"]*|@fortawesome[^'"]*|@tabler[^'"]*|phosphor[^'"]*|@radix-ui\/react-icons))['"]/g);
const inlineSvg = scan(/<svg\b/g, { skip: isShared });
const iconSizes = tally(/\b(?:h|w)-(3|3\.5|4|5|6|7|8|10|12)\b(?=[^"]*\bw-\1\b|[^"]*\bh-\1\b)/g);
const iconSz = (() => { const t = {}; for (const [f, c] of classLits) { const m = c.match(/\bh-(\d+(?:\.\d+)?)\b[^"]*\bw-\1\b|\bw-(\d+(?:\.\d+)?)\b[^"]*\bh-\2\b/); if (m) { const k = m[1] || m[2]; t[k] = (t[k] || 0) + 1; } } return t; })();
const emoji = scan(/[\u{1F300}-\u{1FAFF}\u2600-\u27BF]/gu);
// ---------- spacing, radius, shadow, z
const offScale = tally(/\b(?:p|px|py|pt|pb|pl|pr|m|mx|my|mt|mb|ml|mr|gap|gap-x|gap-y|space-x|space-y)-(0\.5|1\.5|2\.5|3\.5|5|7|9|10|11)\b/g);
const offScaleN = Object.values(offScale).reduce((a, b) => a + b, 0);
const arbSpace = scan(/\b(?:p|px|py|pt|pb|pl|pr|m|mx|my|mt|mb|ml|mr|gap|space-x|space-y|w|h|min-w|min-h|max-w|max-h|top|left|right|bottom|inset)-\[[^\]]+\]/g);
const radius = tally(/\brounded(?:-(?:t|b|l|r|tl|tr|bl|br))?(?:-(none|sm|md|lg|xl|2xl|3xl|full))?\b(?![-\w])/g);
const arbRadius = scan(/\brounded(-[a-z]+)?-\[[^\]]+\]/g);
const shadows = scan(/\b(shadow(?:-[a-z0-9]+)?|ring(?:-[0-9a-z]+)?|drop-shadow|blur(?:-[a-z]+)?|backdrop-blur)\b/g);
const zIdx = tally(/\bz-(\[?-?\d+\]?|auto)\b/g), zBy = scan(/\bz-(\[-?\d+\]|\d+)\b/g);
const zInline = scan(/zIndex\s*:\s*\d+/g);
// ---------- colours
const HUES = 'red|orange|amber|yellow|lime|green|emerald|teal|cyan|sky|blue|indigo|violet|purple|fuchsia|pink|rose|stone|zinc|neutral|gray|slate|navy|brand|surface';
const hue = tally(new RegExp('\\b(?:bg|text|border|ring|fill|stroke|from|to|via|divide|outline|decoration|placeholder|accent|caret)-(' + HUES + ')-\\d{2,3}\\b', 'g'));
const hexTs = scan(/#[0-9a-fA-F]{6}\b|#[0-9a-fA-F]{3}\b(?![0-9a-zA-Z])/g, { skip: (f) => /\.d\.ts/.test(f) });
const rgbTs = scan(/\brgba?\(\s*\d/g);
const styleColor = scan(/style=\{\{[^}]*(color|background|border)[^}]*\}\}/g);
const slate400 = scan(/text-slate-(300|400)\b/g);
const arbColor = scan(/\b(?:bg|text|border|fill|stroke)-\[(?:#|rgb|var\(--c)/g);
const arbColorVar = scan(/\b(?:bg|text|border|fill|stroke)-\[var\(--c-[^\]]+\)\]/g);
const rawHueOutsideTokens = Object.entries(hue).filter(([k]) => !/^(slate|navy|brand|surface)$/.test(k));
// ---------- navigation, page headers
const h1s = scan(/<h1\b/g, { only: (f) => /Pages\//.test(f) });
const pageHeader = scan(/<PageHeader\b/g);
const appLayoutTitle = scan(/<AppLayout\b[^>]*title=/g);
const navItem = scan(/nav-item/g);
const pagesCount = Object.keys(src).filter((f) => /Pages\//.test(f)).length;

// ---------- css token inventory
const cssVars = [...css.matchAll(/^\s*(--[a-z0-9-]+)\s*:\s*([^;]+);/gm)].map((m) => [m[1], m[2].trim()]);
const group = (re) => cssVars.filter(([k]) => re.test(k));
const cssLines = css.split('\n');
let inTokens = false, hardHex = [], hardPx = [], hardZ = [];
cssLines.forEach((l, i) => {
  if (/^\s*(:root|@theme)\b/.test(l)) inTokens = true;
  if (inTokens && /^}/.test(l)) inTokens = false;
  if (inTokens || /^\s*(\/\*|\*)/.test(l) || /ui-pair:/.test(l)) return;
  if (/#[0-9a-fA-F]{3,8}\b/.test(l)) hardHex.push(i + 1);
  if (/z-index\s*:\s*\d+/.test(l)) hardZ.push(i + 1);
  if (/(?:font-size|min-height|min-width|height|width|border-radius)\s*:\s*\d+(\.\d+)?px/.test(l)) hardPx.push(i + 1);
});
const hexByFile = scan(/#[0-9a-fA-F]{6}\b/g);

const W = (n, s) => fs.writeFileSync(path.join(ROOT, 'docs', n), s);
const date = new Date().toLocaleDateString('en-CA');
const sumv = (t) => Object.values(t).reduce((a, b) => a + b, 0);

// =============================== consistency matrix
let m = `# UI consistency matrix (R1)\n\nGenerated ${date} by \`node scripts/ui-audit/static.cjs\` from ${files.length} \`.tsx\` files (${pagesCount} under Pages). Regex based and counting literal class strings, so dynamic class composition is under-counted. "Non-conforming" means: bypasses the shared class or component in \`resources/css/app.css\` / \`Components/ui\` (see docs/UI-STANDARD.md). Re-run the script after changes; this file is overwritten.\n\n`;
m += `## Summary\n\n| Area | Variants found | Non-conforming | Where (top files, count) |\n|---|---|---|---|\n`;
m += `| Buttons | 5 variants (${Object.entries(btnClass).map(([k, v]) => k + ' ' + v).join(', ')}); sizes ${fmt(btnSizes)}; \`<Button>\` component ${compBtn.total} | ${rawBtn.total} raw \`<button>\` without a btn/nav/tab class; ${btnOverride.total} shared-class buttons with local bg/radius/height overrides; ${btnPill.total} pill buttons | ${top(rawBtn, 6)} |\n`;
m += `| Inputs | \`.input\` (shared) | ${ctl.input.total - ctl.input.ok} of ${ctl.input.total} text-like inputs without \`.input\` | ${ctlTop('input')} |\n`;
m += `| Selects | \`.select\` | ${ctl.select.total - ctl.select.ok} of ${ctl.select.total} without \`.select\` | ${ctlTop('select')} |\n`;
m += `| Textareas | \`.textarea\` | ${ctl.textarea.total - ctl.textarea.ok} of ${ctl.textarea.total} without \`.textarea\` | ${ctlTop('textarea')} |\n`;
m += `| Field wrapper | \`<Field>\` ${fieldComp.total} uses, \`.label\` class ${labelCls.total} | n/a | ${top(fieldComp, 4)} |\n`;
m += `| Cards | \`.card\` ${cardCls.total}, \`.stat-tile\` ${statTile.total} | ${adhocCard.total} hand-built bordered white boxes (rounded + border + bg-white, no \`.card\`) | ${adhocCard.byFile.slice(0, 6).map(([f, c]) => '`' + f.replace('resources/js/', '') + '` ' + c).join('; ') || 'none'} |\n`;
m += `| Tabs | \`<Tabs>\` ${tabsComp.total}, \`.tab(s)\` class ${tabCls.total} | ${adhocTabs.total} hand-rolled \`role="tab"\` outside the shared component | ${top(adhocTabs, 6)} |\n`;
m += `| Tables | \`<DataTable>\` ${dataTable.total}, raw \`<table>\` ${tbl.raw}, \`table-cards\` (phone cards) ${cardsTbl.total} | ${tbl.byFile.reduce((a, b) => a + b[1], 0)} raw tables with no \`table-wrap\`/\`table-auto\`/\`table-std\` class | ${tbl.byFile.slice(0, 6).map(([f, c]) => '`' + f.replace('resources/js/', '') + '` ' + c).join('; ') || 'none'} |\n`;
m += `| Modals and sheets | \`<Modal>/<Sheet>\` ${modalComp.total} | ${roleDialog.total} hand-rolled \`role="dialog"\`; ${fixedInset.total} \`fixed inset-0\` overlays outside Modal | ${top(roleDialog, 4)}; ${top(fixedInset, 4)} |\n`;
m += `| Badges | ${Object.keys(badgeCls).length} shared (${fmt(badgeCls, 7)}) | ${adhocBadge.total} hand-built pills (rounded-full + tinted bg, no \`badge-*\`) | ${adhocBadge.byFile.slice(0, 6).map(([f, c]) => '`' + f.replace('resources/js/', '') + '` ' + c).join('; ') || 'none'} |\n`;
m += `| Alerts / callouts | no shared Alert component; \`role="alert"/"status"\` ${roleAlert.total} | ${adhocAlert.total} hand-built tinted callout boxes | ${adhocAlert.byFile.slice(0, 6).map(([f, c]) => '`' + f.replace('resources/js/', '') + '` ' + c).join('; ') || 'none'} |\n`;
m += `| Typography | sizes ${fmt(sizes)}; weights ${fmt(weights, 6)} | ${arbText.total} arbitrary \`text-[Npx]\`; ${upper.total} \`uppercase\`; tracking ${fmt(tracking, 5)} | ${top(arbText, 4)}; uppercase: ${top(upper, 4)} |\n`;
m += `| Icons | ${Object.entries(iconSrc).map(([k, v]) => k + ' ' + v + ' imports').join(', ')}; sizes (h=w) ${fmt(iconSz, 9)} | ${inlineSvg.total} inline \`<svg>\` outside shared; ${emoji.total} emoji-as-icon matches | inline svg: ${top(inlineSvg, 6)}; emoji: ${top(emoji, 4)} |\n`;
m += `| Spacing | Tailwind 4 px base | ${offScaleN} off-scale utilities (${fmt(offScale, 9)}); ${arbSpace.total} arbitrary \`-[Npx]\` sizes or offsets | ${top(arbSpace, 6)} |\n`;
m += `| Colours | palette hues used: ${fmt(Object.fromEntries(Object.entries(hue)), 12)} | ${slate400.total} \`text-slate-300/400\`; ${rawHueOutsideTokens.reduce((a, b) => a + b[1], 0)} utilities from non-brand hues (${rawHueOutsideTokens.map(([k, v]) => k + ' ' + v).join(', ')}) | see docs/UI-TOKENS.md |\n`;
m += `| Shadows / glow | none allowed | ${shadows.total} \`shadow/ring/blur\` utilities in source (neutralised by theme + global \`box-shadow:none\`) | ${top(shadows, 4)} |\n`;
m += `| Radius | ${fmt(radius, 10)} | ${arbRadius.total} arbitrary \`rounded-[..]\` | ${top(arbRadius, 4)} |\n`;
m += `| Navigation | \`nav-item\` ${navItem.total} refs; shell from \`Components/layout\` | n/a (see register for per-route nav state) | |\n`;
m += `| Page headers | \`<PageHeader>\` ${pageHeader.total}, \`<AppLayout title=>\` ${appLayoutTitle.total}, raw \`<h1>\` in Pages ${h1s.total} | ${h1s.total} raw h1 (AppLayout already renders the h1; a second h1 breaks the rule of one per page unless on a public page) | ${top(h1s, 8)} |\n`;
m += `\n## Details\n\n`;
m += `### Buttons\nShared class counts: ${Object.entries(btnClass).map(([k, v]) => '`' + k + '` ' + v).join(', ')}. Hand-rolled buttons (no btn/nav/tab/hit-44 class), top files: ${top(rawBtn, 15)}.\nShared class with local overrides (bg-, rounded-, h-, py-, px-, text size): ${top({ byFile: btnOverride.byFile, total: btnOverride.total }, 10)}.\nPill (rounded-full) buttons: ${btnPill.total}; the directive says "no needless pills".\n\n`;
m += `### Form controls\nText-like inputs ${ctl.input.total} (non-conforming ${ctl.input.total - ctl.input.ok}), selects ${ctl.select.total} (${ctl.select.total - ctl.select.ok}), textareas ${ctl.textarea.total} (${ctl.textarea.total - ctl.textarea.ok}). Checkbox, radio, hidden, file, range and colour inputs are excluded (covered by the global hit-area rule).\n\n`;
m += `### Z-index in source\nUtilities: ${fmt(zIdx, 12)}. Tokens: --z-sticky 20, --z-bar 30, --z-dropdown 40, --z-sheet 50, --z-modal 60, --z-toast 70. Raw numeric \`z-NN\` / \`z-[NN]\` usages: ${zBy.total} (${top(zBy, 8)}); inline \`zIndex:\` ${zInline.total}.\n\n`;
m += `### Variants to consolidate (proposal)\n1. Add a shared \`<Alert tone="info|success|warning|danger">\` (icon + text + role) and replace the ${adhocAlert.total} hand-built callouts. Status then never relies on colour alone.\n2. Replace ${adhocBadge.total} hand-built pills with \`badge-*\` (add \`badge-orange\`-style tones only if a real status needs it).\n3. Replace ${rawBtn.total} raw \`<button>\` with \`<Button>\` or a shared class where they are real actions (tab-like, chip and icon-only toggles may stay but need \`hit-44\`).\n4. Remove local bg/rounded/h overrides on shared btn classes (${btnOverride.total}).\n5. Move ${Object.keys(ctl.input.byFile).length} files' bare inputs to \`.input\`.\n`;
W('UI-CONSISTENCY-MATRIX.md', m);

// =============================== tokens
let t = `# UI tokens inventory (R1)\n\nGenerated ${date} by \`node scripts/ui-audit/static.cjs\`. Source of truth: \`resources/css/app.css\` (\`@theme\` for Tailwind, \`:root\` for semantic \`--c-*\`). Contrast pairs are declared as \`ui-pair:\` comments and checked by \`php scripts/ui-audit/contrast.php\`.\n\n## Inventory in app.css\n\n| Family | Count | Names (first 12) |\n|---|---|---|\n`;
const fam = [['Brand and surface palette', /^--color-(brand|navy|surface)-/], ['Status colours', /^--color-(success|warning|error|info)$/], ['Palette overrides', /^--color-(slate|emerald|amber|red|green)-/], ['Semantic colours (--c-*)', /^--c-/], ['Type scale', /^--text-|^--font-/], ['Radius', /^--radius-/], ['Elevation, blur (all neutralised)', /^--(shadow|inset-shadow|drop-shadow|text-shadow|blur)-/], ['z-index', /^--z-/], ['Other', /^--(?!color-|c-|text-|font-|radius-|shadow|inset|drop|blur|z-)/]];
for (const [name, re] of fam) { const g = group(re); t += `| ${name} | ${g.length} | ${g.slice(0, 12).map(([k]) => '`' + k + '`').join(' ')} |\n`; }
t += `\nTotal custom properties: ${cssVars.length}. Spacing uses Tailwind's 4 px scale (no custom spacing tokens); breakpoints are Tailwind's (sm 640, md 768, lg 1024, xl 1280, 2xl 1536) plus the shell's 768 and 1024 JS thresholds in \`lib/useMediaQuery\`.\n\n`;
t += `### Gaps against the directive (rule 41)\n- No spacing token set (scale 4, 8, 12, 16, 24, 32, 48 is a convention, not enforced). ${offScaleN} utilities use off-scale steps (${fmt(offScale, 9)}).\n- No line-height or letter-spacing tokens beyond the \`--text-*--line-height\` pairs; ${upper.total} \`uppercase\` and ${sumv(tracking)} \`tracking-*\` uses are ad hoc.\n- No \`--bp-*\` tokens (breakpoints live in Tailwind and in JS).\n- z-index: ${zBy.total} raw numeric \`z-NN\`/\`z-[NN]\` usages in components next to the token scale (see below).\n- No max-content-width token: wide-screen behaviour is per-page (see UI-AUDIT-FINAL, ultra-wide findings).\n\n`;
t += `## Hard-coded values outside the tokens\n\n### In app.css (outside :root and @theme)\n- Hex colours: ${hardHex.length} lines (${hardHex.slice(0, 15).join(', ') || 'none'})\n- Fixed z-index numbers: ${hardZ.length} lines (${hardZ.slice(0, 15).join(', ') || 'none'})\n- Fixed px sizes for font-size, height, width, radius: ${hardPx.length} lines (${hardPx.slice(0, 20).join(', ') || 'none'})\n\n`;
t += `### In resources/js (counts per file)\n\n| Kind | Total | Top files |\n|---|---|---|\n`;
t += `| Hex colour literals (#rrggbb) | ${hexByFile.total} | ${top(hexByFile, 10)} |\n`;
t += `| \`rgb()/rgba()\` literals | ${rgbTs.total} | ${top(rgbTs, 8)} |\n`;
t += `| \`style={{ color/background/border }}\` | ${styleColor.total} | ${top(styleColor, 8)} |\n`;
t += `| Arbitrary colour utilities \`bg-[#..]\`, \`text-[var(--c-..)]\` | ${arbColor.total} (of which \`var(--c-*)\`, i.e. token based: ${arbColorVar.total}) | ${top(arbColor, 8)} |\n`;
t += `| Non-brand Tailwind hues (red, amber, emerald, green, blue, orange, yellow, rose, purple, ...) | ${rawHueOutsideTokens.reduce((a, b) => a + b[1], 0)} | ${rawHueOutsideTokens.map(([k, v]) => k + ' ' + v).join(', ')} |\n`;
t += `| \`text-slate-300/400\` (low contrast on light surfaces) | ${slate400.total} | ${top(slate400, 8)} |\n`;
t += `| Arbitrary font sizes \`text-[Npx]\` | ${arbText.total} | ${top(arbText, 8)} |\n`;
t += `| Arbitrary spacing and size values \`w-[..]\`, \`p-[..]\`, \`top-[..]\` | ${arbSpace.total} | ${top(arbSpace, 10)} |\n`;
t += `| Arbitrary radius \`rounded-[..]\` | ${arbRadius.total} | ${top(arbRadius, 6)} |\n`;
t += `| Numeric z-index \`z-NN\`, \`z-[NN]\` | ${zBy.total} | ${top(zBy, 10)} |\n`;
t += `| Inline \`zIndex:\` | ${zInline.total} | ${top(zInline, 6)} |\n`;
t += `| Off-scale spacing utilities (${Object.keys(offScale).join(', ')}) | ${offScaleN} | by step: ${fmt(offScale, 10)} |\n`;
t += `| Shadow / ring / blur utilities | ${shadows.total} | ${top(shadows, 6)} |\n`;
t += `\n### Tailwind palette use by hue\n${fmt(hue, 30)}\n\n### z-index utilities in use\n${fmt(zIdx, 20)}\n\n### Radius utilities in use\n${fmt(radius, 12)}\n`;
W('UI-TOKENS.md', t);
fs.mkdirSync(path.join(__dirname, 'results'), { recursive: true });
const summary = `${compBtn.total} <Button> uses and ${sumv(btnClass)} shared btn-* class uses against ${rawBtn.total} raw buttons and ${btnOverride.total} overridden shared buttons; text inputs ${ctl.input.total - ctl.input.ok}/${ctl.input.total}, selects ${ctl.select.total - ctl.select.ok}/${ctl.select.total}, textareas ${ctl.textarea.total - ctl.textarea.ok}/${ctl.textarea.total} without the shared class; ${adhocCard.total} hand-built cards, ${adhocBadge.total} hand-built badges, ${adhocAlert.total} hand-built alert boxes (no shared Alert component), ${tbl.byFile.reduce((a, b) => a + b[1], 0)} raw tables without a wrapper class, ${roleDialog.total + fixedInset.total} hand-rolled overlays; ${rgbTs.total + hexByFile.total} hard-coded colour literals in components, ${rawHueOutsideTokens.reduce((a, b) => a + b[1], 0)} non-brand Tailwind hue utilities (semantic status colours), ${offScaleN} off-scale spacing utilities, ${zBy.total} raw z-index numbers, ${upper.total} uppercase uses, ${arbText.total} arbitrary font sizes, ${shadows.total} shadow/ring utilities.`;
fs.writeFileSync(path.join(__dirname, 'results', 'static.json'), JSON.stringify({ summary }, null, 1));
console.log('wrote docs/UI-CONSISTENCY-MATRIX.md and docs/UI-TOKENS.md');
