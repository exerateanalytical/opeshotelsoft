/*
 * Fresh-load confirmation of P0-P2 findings (workstream R1).
 *   node scripts/ui-audit/recheck.cjs [--base http://localhost:8120] [--conc 3]
 * run.cjs loads each route once per input mode and resizes in place, which can leave layout artefacts (JS measured at the
 * first width). For every route x rule of severity P0-P2 this script reloads the page from scratch at up to three of the
 * widths where the rule fired (first, middle, last) and records whether it fired again. register.cjs then reports only
 * confirmed findings. Output: scripts/ui-audit/results/recheck.jsonl
 */
const fs = require('fs');
const path = require('path');
const { createRequire } = require('module');
const req = createRequire(process.env.PUPPETEER_CORE ? path.join(process.env.PUPPETEER_CORE, 'x.js') : __filename);
const puppeteer = req('puppeteer-core');
const { RULES } = require('./rules.cjs');
const arg = (k, d) => { const i = process.argv.indexOf('--' + k); return i > -1 ? process.argv[i + 1] : d; };
const BASE = arg('base', 'http://localhost:8120');
const CONC = parseInt(arg('conc', '3'), 10);
const CHROME = process.env.CHROME_PATH || 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const RAW = path.join(__dirname, 'results', 'raw');
const OUT = path.join(__dirname, 'results', 'recheck.jsonl');
const AUDIT = fs.readFileSync(path.join(__dirname, 'audit.js'), 'utf8');
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const WHO = { gm: 'gm', cashier: 'cashier', member: 1, guest: null };

// collect: key -> { kind, role, route, rules: { rule: [vp objects] } }
const tasks = new Map();
for (const f of fs.readdirSync(RAW).filter((x) => x.endsWith('.jsonl') && !x.startsWith('modals'))) {
  for (const l of fs.readFileSync(path.join(RAW, f), 'utf8').split('\n')) {
    if (!l.trim()) continue;
    let r; try { r = JSON.parse(l); } catch (e) { continue; }
    if (!r.report || !r.report.counts) continue;
    for (const rule of Object.keys(r.report.counts)) {
      if (!RULES[rule] || RULES[rule][0] === 'P3' || rule === 'load-fail') continue;
      const key = r.kind + '|' + r.role + '|' + r.route;
      const t = tasks.get(key) || { kind: r.kind, role: r.role, route: r.route, rules: {} };
      (t.rules[rule] = t.rules[rule] || []).push({ vp: r.vp, width: r.width, height: r.height, dpr: r.dpr });
      tasks.set(key, t);
    }
  }
}
// per task: pick up to 3 vps per rule, then the union of vps
const jobs = [];
for (const t of tasks.values()) {
  const need = new Map();
  for (const [rule, vps] of Object.entries(t.rules)) {
    const pick = [vps[0], vps[Math.floor(vps.length / 2)], vps[vps.length - 1]];
    for (const v of pick) need.set(v.vp, v);
  }
  jobs.push({ ...t, vps: [...need.values()] });
}
console.log('routes to recheck', jobs.length, 'loads', jobs.reduce((a, j) => a + j.vps.length, 0));

async function login(ctx, role) {
  const page = await ctx.newPage();
  await page.goto(BASE + '/login' + (role === 'member' ? '?tab=member' : ''), { waitUntil: 'domcontentloaded' });
  const target = role === 'member' ? await page.evaluate(() => { try { return JSON.parse(document.getElementById('app').dataset.page).props.memberDemoAccounts[0].n; } catch (e) { return 1; } }) : WHO[role];
  await page.evaluate(async (role, target) => {
    const tok = decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || '');
    await fetch(role === 'member' ? '/demo-member-login/' + target : '/demo-login/' + target, { method: 'POST', headers: { 'X-XSRF-TOKEN': tok, 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' });
  }, role, target);
  await page.close();
}

(async () => {
  const ws = fs.createWriteStream(OUT);
  const queue = jobs.slice();
  let done = 0;
  await Promise.all(Array.from({ length: CONC }, async () => {
    const br = await puppeteer.launch({ executablePath: CHROME, headless: true, args: ['--no-sandbox', '--disable-gpu'] });
    const ctxs = {};
    while (queue.length) {
      const j = queue.shift();
      try {
        if (!ctxs[j.role]) { ctxs[j.role] = await br.createBrowserContext(); if (WHO[j.role] !== null) await login(ctxs[j.role], j.role); }
        for (const v of j.vps) {
          const page = await ctxs[j.role].newPage();
          try {
            await page.setViewport({ width: v.width, height: v.height, deviceScaleFactor: v.dpr || 1, isMobile: v.width < 768 && !/^(zoom|text)/.test(v.vp), hasTouch: v.width < 1024 && !/^(zoom|text)/.test(v.vp) });
            await page.goto(BASE + j.route, { waitUntil: 'domcontentloaded', timeout: 30000 });
            for (let i = 0; i < 30 && !(await page.$('h1')); i++) await sleep(250);
            await sleep(900);
            await page.evaluate(AUDIT);
            if (/^text/.test(v.vp)) await page.evaluate(() => { document.documentElement.style.fontSize = '200%'; });
            await sleep(200);
            const rep = await page.evaluate(() => window.__uiAudit());
            ws.write(JSON.stringify({ kind: j.kind, role: j.role, route: j.route, vp: v.vp, counts: rep.counts, samples: Object.fromEntries(Object.entries(rep.samples).map(([k, a]) => [k, a.slice(0, 2)])) }) + '\n');
          } catch (e) { ws.write(JSON.stringify({ kind: j.kind, role: j.role, route: j.route, vp: v.vp, error: String(e).slice(0, 120) }) + '\n'); }
          await page.close().catch(() => {});
        }
      } catch (e) { /* login failure: skip */ }
      done++; if (done % 10 === 0) console.log('recheck', done + '/' + jobs.length);
    }
    await br.close();
  }));
  ws.end();
  console.log('done');
})().catch((e) => { console.error(e); process.exit(1); });
