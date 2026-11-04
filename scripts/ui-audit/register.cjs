/*
 * Builds the UI screen register and the final audit report from the raw harness output (workstream R1).
 *   node scripts/ui-audit/register.cjs
 * Reads  scripts/ui-audit/results/raw/*.jsonl  (written by run.cjs and modals.cjs, one JSON object per route x viewport)
 * Writes scripts/ui-audit/results/summary.json, scripts/ui-audit/results/register.csv, docs/UI-REGISTER.md, docs/UI-AUDIT-FINAL.md
 * Pass rule: a form factor PASSES when no finding of severity P0, P1 or P2 occurs at any of its widths; P3 findings are polish
 * and are reported but do not fail ("PASS*" = passes with P3 polish). P0 is unusable or an access/functional blocker.
 */
const fs = require('fs');
const path = require('path');
const ROOT = path.join(__dirname, '..', '..');
const RAW = path.join(__dirname, 'results', 'raw');
const STATIC = path.join(__dirname, 'results', 'static.json');

const { RULES } = require('./rules.cjs');
const ORDER = { P0: 0, P1: 1, P2: 2, P3: 3 };
const FF = (w) => (w < 768 ? 'mobile' : w <= 1024 ? 'tablet' : 'desktop');

const rows = [];
for (const f of fs.existsSync(RAW) ? fs.readdirSync(RAW).filter((x) => x.endsWith('.jsonl') && !x.startsWith('modals')) : []) {
  for (const l of fs.readFileSync(path.join(RAW, f), 'utf8').split('\n')) { if (l.trim()) { try { rows.push(JSON.parse(l)); } catch (e) { /* partial last line */ } } }
}
const modals = fs.existsSync(path.join(RAW, 'modals.jsonl')) ? fs.readFileSync(path.join(RAW, 'modals.jsonl'), 'utf8').split('\n').filter(Boolean).map((l) => { try { return JSON.parse(l); } catch (e) { return null; } }).filter(Boolean) : [];

// ---------- fresh-load confirmation (recheck.cjs): a P0-P2 finding that did not reproduce on a fresh load at any rechecked width is a resize artefact and is dropped
const recheck = new Map();
const RC = path.join(__dirname, 'results', 'recheck.jsonl');
if (fs.existsSync(RC)) for (const l of fs.readFileSync(RC, 'utf8').split('\n')) { if (!l.trim()) continue; let r; try { r = JSON.parse(l); } catch (e) { continue; } if (r.error) continue; const k = r.kind + '|' + r.role + '|' + r.route; const m = recheck.get(k) || {}; for (const rule of Object.keys(RULES)) { const e = (m[rule] = m[rule] || { checked: 0, fired: 0 }); e.checked++; if (r.counts && r.counts[rule]) e.fired++; } recheck.set(k, m); }
let artefacts = 0, confirmed = 0;
const isArtefact = (key, rule) => { const m = recheck.get(key); if (!m || !m[rule] || RULES[rule][0] === 'P3') return false; return m[rule].checked > 0 && m[rule].fired === 0; };

// ---------- group by screen
const screens = new Map();
for (const r of rows) {
  const key = r.kind + '|' + r.role + '|' + r.route;
  if (!screens.has(key)) screens.set(key, { kind: r.kind, role: r.role, route: r.route, status: r.status, finalUrl: r.finalUrl, vps: [], errs: [], loadFail: false });
  const s = screens.get(key);
  s.status = Math.max(s.status || 0, r.status || 0) === r.status ? r.status : s.status;
  if (r.vp === 'fail' || !r.report || r.report.error) { s.loadFail = s.loadFail || (r.error || (r.report && r.report.error) || 'fail'); continue; }
  if (r.errs && r.errs.length) s.errs.push(...r.errs);
  s.vps.push(r);
}
const ROLE_NAME = { gm: 'General Manager', guest: 'Guest (signed out)', member: 'Loyalty member', cashier: 'Cashier' };
const screenName = (route, kind) => {
  if (route === '/') return 'Landing';
  const seg = route.split('/').filter(Boolean).map((x) => x.replace(/-/g, ' '));
  return seg.map((x) => x[0].toUpperCase() + x.slice(1)).join(' / ');
};

const findingsBySeverity = { P0: 0, P1: 0, P2: 0, P3: 0 };
const ruleTotals = {};
const out = [];
for (const s of screens.values()) {
  const perFF = { mobile: {}, tablet: {}, desktop: {}, zoom: {} };
  const widthsByRule = {};
  const sample = {};
  for (const v of s.vps) {
    const w = /^(zoom|text)/.test(v.vp) ? 'zoom' : FF(v.width);
    for (const [rule, n] of Object.entries(v.report.counts || {})) {
      if (!RULES[rule]) continue;
      if (isArtefact(s.kind + '|' + s.role + '|' + s.route, rule)) { artefacts++; continue; }
      const slot = (perFF[w][rule] = perFF[w][rule] || { max: 0, vps: [] });
      slot.max = Math.max(slot.max, n); slot.vps.push(v.vp);
      (widthsByRule[rule] = widthsByRule[rule] || new Set()).add(v.vp);
      if (!sample[rule] && v.report.samples && v.report.samples[rule]) sample[rule] = v.report.samples[rule][0];
    }
  }
  if (s.loadFail && !s.vps.length) { perFF.mobile['load-fail'] = perFF.tablet['load-fail'] = perFF.desktop['load-fail'] = { max: 1, vps: ['all'] }; widthsByRule['load-fail'] = new Set(['all']); sample['load-fail'] = { el: String(s.loadFail) }; }
  else if (s.errs.length && s.vps.length) { const e = s.errs[0]; if (/TypeError|ReferenceError|Cannot read|is not a function/.test(e)) { perFF.desktop['load-fail'] = { max: 1, vps: ['page-error'] }; widthsByRule['load-fail'] = new Set(['page-error']); sample['load-fail'] = { el: e }; } }
  const sev = (ff) => Object.keys(perFF[ff]).map((r) => RULES[r][0]).sort((a, b) => ORDER[a] - ORDER[b]);
  const status = (ff) => {
    const sv = sev(ff);
    if (!s.vps.length && !perFF[ff]['load-fail']) return 'n/a';
    if (sv.some((x) => ORDER[x] <= 2)) return 'FAIL ' + sv[0];
    if (sv.length) return 'PASS*';
    return 'PASS';
  };
  const all = Object.keys(widthsByRule).sort((a, b) => ORDER[RULES[a][0]] - ORDER[RULES[b][0]]);
  const worst = all.length ? RULES[all[0]][0] : '-';
  const cell = (groups) => {
    const hits = all.filter((r) => groups.includes(RULES[r][1]));
    if (!hits.length) return 'OK';
    return hits.map((r) => r + ' (' + [...widthsByRule[r]].slice(0, 4).join(',') + ([...widthsByRule[r]].length > 4 ? '...' : '') + ')').join('; ');
  };
  const rec = all.filter((r) => ORDER[RULES[r][0]] <= 2).slice(0, 3).map((r) => RULES[r][3] + (sample[r] ? ' Sample: `' + String(sample[r].el).slice(0, 70).replace(/\|/g, '/') + '`.' : ''));
  for (const r of all) { findingsBySeverity[RULES[r][0]]++; ruleTotals[r] = (ruleTotals[r] || 0) + 1; }
  const blockers = all.filter((r) => ORDER[RULES[r][0]] <= 1).map((r) => r + ' (' + RULES[r][0] + ')');
  const mod = modals.filter((m) => m.route === s.route);
  out.push({
    kind: s.kind, role: ROLE_NAME[s.role] || s.role, route: s.route, name: screenName(s.route, s.kind), httpStatus: s.status, finalUrl: s.finalUrl,
    desktop: status('desktop'), tablet: status('tablet'), mobile: status('mobile'), zoom: status('zoom'),
    colour: cell(['colour']), typography: cell(['typography']), spacing: cell(['overlap']), responsiveness: cell(['responsive']),
    accessibility: cell(['accessibility', 'access']), components: cell(['component']), overlapDistortion: cell(['distortion', 'overlap']),
    severity: worst, rec: rec.length ? rec.join(' ') : (all.length ? 'Polish only (P3): ' + all.map((r) => RULES[r][3]).slice(0, 2).join(' ') : 'none'),
    fixed: all.length ? (blockers.length ? 'No' : 'Partly (P2/P3 open)') : 'Yes (clean)', blocker: blockers.join(', ') || 'none',
    rules: Object.fromEntries(all.map((r) => [r, [...widthsByRule[r]]])), perFF: Object.fromEntries(Object.entries(perFF).map(([k, v]) => [k, Object.fromEntries(Object.entries(v).map(([r, x]) => [r, x.max]))])),
    modals: mod.length ? mod.length : 0, nVps: s.vps.length,
  });
}
out.sort((a, b) => (a.kind + a.route).localeCompare(b.kind + b.route));

// ---------- modal findings
const modalFail = [];
for (const m of modals) {
  if (!m.opened) continue;
  const bad = [];
  if (!m.semantic) bad.push('no role=dialog');
  if (!m.fitsW) bad.push('wider than viewport');
  if (!m.fitsH) bad.push('taller than viewport');
  if (m.footerReachable === false) bad.push('footer buttons not reachable');
  if (!m.hasClose) bad.push('no close button');
  if (!m.focusInside) bad.push('focus not moved into dialog');
  if (m.tabTrapped === false) bad.push('Tab escapes the dialog');
  if (m.escapeCloses === false) bad.push('Escape does not close');
  if (bad.length) modalFail.push({ route: m.route, w: m.w, trigger: m.trigger, bad });
}
const modalSummary = { triggers: modals.filter((m) => m.trigger).length, opened: modals.filter((m) => m.opened).length, failing: modalFail.length, pagesWithoutTrigger: modals.filter((m) => m.trigger === null).length };

// ---------- stats
const stat = { total: out.length, byKind: {}, ff: {} };
for (const o of out) stat.byKind[o.kind] = (stat.byKind[o.kind] || 0) + 1;
for (const ff of ['mobile', 'tablet', 'desktop', 'zoom']) {
  const arr = out.filter((o) => o[ff] !== 'n/a');
  stat.ff[ff] = { audited: arr.length, pass: arr.filter((o) => o[ff] === 'PASS').length, passPolish: arr.filter((o) => o[ff] === 'PASS*').length, fail: arr.filter((o) => o[ff].startsWith('FAIL')).length };
}
const sevRoutes = { P0: 0, P1: 0, P2: 0, P3: 0, clean: 0 };
for (const o of out) { if (o.severity === '-') sevRoutes.clean++; else sevRoutes[o.severity]++; }
// per-width critical counts across routes
const perWidth = {};
for (const r of rows) { if (!r.report || r.report.error) continue; const k = r.vp; const e = (perWidth[k] = perWidth[k] || { width: r.width, routes: 0, withCritical: 0, critical: 0, withAdvisory: 0, advisory: 0 }); e.routes++; if (r.report.critical) { e.withCritical++; e.critical += r.report.critical; } if (r.report.advisory) { e.withAdvisory++; e.advisory += r.report.advisory; } }
const ruleWidth = {};
for (const r of rows) { if (!r.report || !r.report.counts) continue; for (const [rule, n] of Object.entries(r.report.counts)) { if (!RULES[rule]) continue; const e = (ruleWidth[rule] = ruleWidth[rule] || {}); e[r.vp] = (e[r.vp] || 0) + 1; } }

fs.writeFileSync(path.join(__dirname, 'results', 'summary.json'), JSON.stringify({ stat, sevRoutes, findingsBySeverity, ruleTotals, perWidth, modalSummary, modalFail, screens: out }, null, 1));
fs.writeFileSync(path.join(__dirname, 'results', 'register.csv'), ['kind,role,route,name,mobile,tablet,desktop,zoom,severity,fixed,blocker'].concat(out.map((o) => [o.kind, o.role, o.route, o.name, o.mobile, o.tablet, o.desktop, o.zoom, o.severity, o.fixed, o.blocker].map((x) => '"' + String(x).replace(/"/g, '""') + '"').join(','))).join('\n'));

// ---------- UI-REGISTER.md
const date = new Date().toLocaleDateString('en-CA');
const nPhone = new Set(rows.filter((r) => r.mode === 'phone').map((r) => r.width)).size;
let md = `# UI screen register (R1)\n\nGenerated ${date} by \`node scripts/ui-audit/register.cjs\` from \`scripts/ui-audit/results/raw/*.jsonl\` (headless Chrome, harness \`scripts/ui-audit/audit.js\` v2, runner \`scripts/ui-audit/run.cjs\`). One row per discoverable route: ${out.length} screens (${Object.entries(stat.byKind).map(([k, v]) => v + ' ' + k).join(', ')}). Do not edit by hand; re-run the scripts.\n\n`;
md += `**Legend.** Status per form factor (worst of its widths): mobile 320-480, tablet 768-1024, desktop 1280-2560, zoom = emulated browser zoom 125/150/200 percent at 1280 and 1920 plus 200 percent text scale. \`PASS\` nothing found; \`PASS*\` only P3 polish; \`FAIL Pn\` a P0-P2 finding. Severity: P0 unusable, access or functional blocker; P1 major (overflow, overlap, contrast, accessibility, distortion, glow); P2 moderate (touch size, small text, stretched layout, component variants); P3 polish. Cells list the harness rule and the viewports where it fired. "Fixed or not": Yes (clean) = nothing found now (earlier UX workstreams fixed the defects found at 375/768/1280); No = a P0/P1 remains; Partly = only P2/P3 open. Roles: staff routes were audited as General Manager (demo login \`gm\`); see UI-AUDIT-FINAL for role coverage.\n\n`;
md += `| # | Screen | Route | Role | Desktop | Tablet | Mobile | Zoom | Colour | Typography | Spacing | Responsiveness | Accessibility | Component inconsistencies | Overlap / distortion | Sev | Recommended fix | Fixed? | Remaining blocker |\n|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|\n`;
const esc = (x) => String(x).replace(/\|/g, '/').replace(/\n/g, ' ');
out.forEach((o, i) => { md += `| ${i + 1} | ${esc(o.name)} | \`${esc(o.route)}\` | ${esc(o.role)} | ${o.desktop} | ${o.tablet} | ${o.mobile} | ${o.zoom} | ${esc(o.colour)} | ${esc(o.typography)} | ${esc(o.spacing)} | ${esc(o.responsiveness)} | ${esc(o.accessibility)}${o.modals ? ' (modal checks: see FINAL)' : ''} | ${esc(o.components)} | ${esc(o.overlapDistortion)} | ${o.severity} | ${esc(o.rec)} | ${o.fixed} | ${esc(o.blocker)} |\n`; });
fs.writeFileSync(path.join(ROOT, 'docs', 'UI-REGISTER.md'), md);

// ---------- UI-AUDIT-FINAL.md
const st = fs.existsSync(STATIC) ? JSON.parse(fs.readFileSync(STATIC, 'utf8')) : {};
const FIXES = path.join(__dirname, 'results', 'fixes.md');
if (fs.existsSync(FIXES)) st.fixes = fs.readFileSync(FIXES, 'utf8');
const pct = (a, b) => (b ? Math.round((a / b) * 100) + '%' : '-');
const widthsOrder = Object.values(perWidth).sort((a, b) => a.width - b.width || a.routes - b.routes);
const topRules = Object.entries(ruleTotals).sort((a, b) => ORDER[RULES[a[0]][0]] - ORDER[RULES[b[0]][0]] || b[1] - a[1]);
const list = (arr, n = 12) => arr.slice(0, n).map((o) => '`' + o.route + '`').join(', ') + (arr.length > n ? ', and ' + (arr.length - n) + ' more' : '');
const withRule = (rule) => out.filter((o) => o.rules[rule]);
let f = `# UI audit: final report (R1)\n\nGenerated ${date} by \`node scripts/ui-audit/register.cjs\`. Contract: docs/UI-DIRECTIVE.md. Method, harness and scope: docs/UI-STANDARD.md and the notes under A.\n\n## A. Executive summary\n\n`;
f += `**Screens audited: ${out.length}** (${Object.entries(stat.byKind).map(([k, v]) => v + ' ' + k).join(', ')}), each at ${Object.keys(perWidth).length} viewport configurations: phones ${[320, 360, 375, 390, 412, 430, 480].join(', ')}; tablets 768, 820, 834, 1024; desktops 1280, 1366, 1440, 1536, 1600, 1920, 2560; emulated zoom 125/150/200 percent at 1280 and 1920 (CSS widths 1024, 853, 640 and 1536, 1280, 960 with matching device pixel ratio); 200 percent text scale at 1280. Total harness runs: ${rows.length - rows.filter((r) => r.vp === 'fail').length}.\n\n`;
f += `| Form factor | Screens audited | PASS | PASS* (P3 polish only) | FAIL (P0-P2) | Pass rate (PASS + PASS*) |\n|---|---|---|---|---|---|\n`;
for (const ff of ['mobile', 'tablet', 'desktop', 'zoom']) { const x = stat.ff[ff]; f += `| ${ff} | ${x.audited} | ${x.pass} | ${x.passPolish} | ${x.fail} | ${pct(x.pass + x.passPolish, x.audited)} |\n`; }
f += `\n**Screens needing correction** (any P0-P2): ${out.filter((o) => ORDER[o.severity] <= 2 && o.severity !== '-').length} of ${out.length}. Clean: ${sevRoutes.clean}. P3 polish only: ${sevRoutes.P3}.\n\n`;
f += `| Severity | Screens whose worst finding is this | Distinct findings (rule x screen) |\n|---|---|---|\n`;
for (const p of ['P0', 'P1', 'P2', 'P3']) f += `| ${p} | ${sevRoutes[p]} | ${findingsBySeverity[p]} |\n`;
f += `\nModal and sheet checks (${modalSummary.triggers} triggers tried on up to 29 pages at 320, 375 and 1280 px; ${modalSummary.opened} opened a dialog; ${modalSummary.failing} dialog openings had at least one failed criterion; ${modalSummary.pagesWithoutTrigger} page-viewport pairs had no "new/add/edit" trigger). Details under D.\n\n`;
f += `### Honest scope notes\n- Runner: headless Chrome 156 driven by \`scripts/ui-audit/run.cjs\` (puppeteer-core) on my own server http://localhost:8120, not iframes or the shared browser pane; real viewport emulation (mobile and touch flags for phones, touch for tablets). Each route is loaded once per input mode and resized in place for every width, so a width that only differs by viewport (not by load-time logic) is exact.\n- Zoom is an emulation: CSS width = screen width / zoom, device pixel ratio = zoom. It reproduces layout reflow at zoomed widths, not the browser zoom UI.\n- Roles: staff pages were audited as General Manager (hotel scoped, sees nearly all); routes that GM cannot open are listed in G. Cashier was used for the 403 page. Role-fitted home pages per role were not exercised in this pass.\n- Heuristic rules (long-line, stretched, uppercase-block, btn-nonstandard, icon-family, colour-only-status) can over- or under-report; samples are in \`results/summary.json\`. The repository is not under git, so there is no before/after diff for this pass; "Fixed" means clean at the time of this run.\n\n`;
f += `## B. Design-system findings\n\n`;
f += `Static counts (docs/UI-CONSISTENCY-MATRIX.md, docs/UI-TOKENS.md): ${st.summary || 'run static.cjs first'}\n\n`;
const grp = (g) => topRules.filter(([r]) => RULES[r][1] === g);
f += `Harness findings in the component and colour families (screens affected out of ${out.length}):\n\n| Rule | Severity | What | Screens |\n|---|---|---|---|\n`;
for (const [r, n] of topRules.filter(([r]) => ['component', 'colour'].includes(RULES[r][1]))) f += `| ${r} | ${RULES[r][0]} | ${RULES[r][2]} | ${n} |\n`;
f += `\nTop offenders: ${['btn-variant', 'btn-nonstandard', 'icon-family', 'nested-card', 'radius-off-scale', 'icon-stroke', 'contrast', 'glow'].filter((r) => withRule(r).length).map((r) => '**' + r + '**: ' + list(withRule(r), 8)).join('; ') || 'none'}.\n\n`;
f += `## C. Responsive findings\n\n### Findings per viewport (all screens)\n\n| Viewport | Screens run | With a critical finding | Critical findings | With an advisory finding | Advisory findings |\n|---|---|---|---|---|---|\n`;
for (const w of widthsOrder) f += `| ${Object.keys(perWidth).find((k) => perWidth[k] === w)} | ${w.routes} | ${w.withCritical} | ${w.critical} | ${w.withAdvisory} | ${w.advisory} |\n`;
f += `\n### Mobile (320-480)\nScreens failing at mobile: ${list(out.filter((o) => o.mobile.startsWith('FAIL')))}.\nHorizontal overflow: ${list(withRule('overflow-x')) || 'none'}.\nTouch targets under 44 px: ${list(withRule('touch-target')) || 'none'}.\n\n### Tablet (768-1024)\nScreens failing at tablet: ${list(out.filter((o) => o.tablet.startsWith('FAIL')))}.\n\n### Desktop (1280-1920)\nScreens failing at desktop: ${list(out.filter((o) => o.desktop.startsWith('FAIL')))}.\n\n### Ultra-wide (1920, 2560) and long lines\nStretched forms, inputs or tables (>720 px control, >1100 px form, sparse table): ${list(withRule('stretched'), 30) || 'none'}.\nText lines over about 90 characters: ${list(withRule('long-line'), 30) || 'none'}.\nWidest content block per page at 2560 (metric contentW, px; pages above 1700 px listed): ${rows.filter((r) => r.vp === '2560x1440' && r.report && r.report.metrics && r.report.metrics.contentW > 1700).map((r) => '`' + r.route + '` ' + r.report.metrics.contentW).slice(0, 25).join(', ') || 'none'}.\n\n### Zoom (125/150/200 percent, emulated)\nScreens failing under zoom: ${list(out.filter((o) => o.zoom.startsWith('FAIL')), 40)}.\n\n`;
f += `## D. Accessibility findings\n\n| Rule | Severity | Screens affected |\n|---|---|---|\n`;
for (const [r, n] of topRules.filter(([r]) => ['accessibility', 'access'].includes(RULES[r][1]))) f += `| ${r}: ${RULES[r][2]} | ${RULES[r][0]} | ${n} |\n`;
f += `\nNo-label controls: ${list(withRule('no-label'), 20) || 'none'}. Contrast: ${list(withRule('contrast'), 20) || 'none'}. Colour-only status: ${list(withRule('colour-only-status'), 20) || 'none'}. Missing alt: ${list(withRule('img-alt'), 20) || 'none'}.\n\n### Modals and sheets\n`;
f += modalFail.length ? `| Page | Viewport | Trigger | Failed criteria |\n|---|---|---|---|\n` + modalFail.slice(0, 80).map((m) => `| \`${m.route}\` | ${m.w} | ${esc(m.trigger)} | ${m.bad.join(', ')} |`).join('\n') + '\n' : 'All dialogs that opened passed width, height, close, Escape, focus and Tab-trap checks.\n';
f += `\n## E. Page register\n\nOne row per route, all fields of the directive: **docs/UI-REGISTER.md** (${out.length} rows; machine readable: scripts/ui-audit/results/register.csv and summary.json).\n\n## F. Fix register\n\n`;
f += `### Findings by rule (screens affected, severity order)\n\n| Rule | Sev | Screens | Proposed fix |\n|---|---|---|---|\n`;
for (const [r, n] of topRules) f += `| ${r} (${RULES[r][2]}) | ${RULES[r][0]} | ${n} | ${RULES[r][3]} |\n`;
f += `\n### Fixes applied in R1\n${st.fixes || '(see the list in the handoff report)'}\n\n`;
f += `## G. Remaining blockers\n\n`;
const blk = out.filter((o) => o.blocker !== 'none');
f += blk.length ? `| Screen | Route | Blocker |\n|---|---|---|\n` + blk.slice(0, 120).map((o) => `| ${esc(o.name)} | \`${o.route}\` | ${esc(o.blocker)} |`).join('\n') + '\n' : 'No P0 or P1 findings remain on any audited screen.\n';
const noAccess = out.filter((o) => (o.httpStatus && o.httpStatus >= 400) || (o.kind === 'staff' && o.finalUrl && o.finalUrl !== o.route));
f += `\nRoutes that did not render their own page for this role (HTTP error or redirect): ${noAccess.length ? noAccess.map((o) => '`' + o.route + '` (' + o.httpStatus + (o.finalUrl !== o.route ? ' -> ' + o.finalUrl : '') + ')').join(', ') : 'none'}.\n`;
fs.writeFileSync(path.join(ROOT, 'docs', 'UI-AUDIT-FINAL.md'), f);
console.log(JSON.stringify({ recheckedScreens: recheck.size, artefactsDropped: artefacts, screens: out.length, stat: stat.ff, sevRoutes, findingsBySeverity, modalSummary }, null, 1));
