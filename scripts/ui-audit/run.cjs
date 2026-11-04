/*
 * Headless matrix runner for scripts/ui-audit/audit.js (workstream R1).
 *   node scripts/ui-audit/run.cjs [--base http://localhost:8120] [--only staff|public|member|errors|roles] [--limit N] [--conc 3]
 * Needs puppeteer-core (set PUPPETEER_CORE to its folder if it is not installed in the repo) and Chrome (CHROME_PATH).
 * Each route is loaded once per input mode (phone: mobile+touch, tablet: touch, desktop: mouse) and the viewport is
 * resized in place for every width of that mode, so the matrix is 3 loads per route. Browser zoom 125/150/200 percent is
 * emulated with the equivalent CSS width and deviceScaleFactor (this is an emulation, not the browser zoom UI).
 * Raw output: scripts/ui-audit/results/raw/<kind>.jsonl (one JSON object per route x width).
 */
const fs = require('fs');
const path = require('path');
const { createRequire } = require('module');
const req = createRequire(process.env.PUPPETEER_CORE ? path.join(process.env.PUPPETEER_CORE, 'x.js') : __filename);
const puppeteer = req('puppeteer-core');

const arg = (k, d) => { const i = process.argv.indexOf('--' + k); return i > -1 ? process.argv[i + 1] : d; };
const BASE = arg('base', 'http://localhost:8120');
const ONLY = arg('only', 'all');
const LIMIT = parseInt(arg('limit', '0'), 10);
const CONC = parseInt(arg('conc', '3'), 10);
const CHROME = process.env.CHROME_PATH || 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const OUT = path.join(__dirname, 'results', 'raw');
fs.mkdirSync(OUT, { recursive: true });
const AUDIT = fs.readFileSync(path.join(__dirname, 'audit.js'), 'utf8');
const routes = JSON.parse(fs.readFileSync(path.join(__dirname, 'routes.json'), 'utf8'));

const MATRIX = {
  phone: [[320, 640], [360, 740], [375, 812], [390, 844], [412, 915], [430, 932], [480, 854]],
  tablet: [[768, 1024], [820, 1180], [834, 1194], [1024, 768]],
  desktop: [[1280, 720], [1366, 768], [1440, 900], [1536, 864], [1600, 900], [1920, 1080], [2560, 1440]],
};
// zoom emulation at 1280x720 and 1920x1080: css width = width / zoom, deviceScaleFactor = zoom
const ZOOMS = [[1280, 720, 1.25], [1280, 720, 1.5], [1280, 720, 2], [1920, 1080, 1.25], [1920, 1080, 1.5], [1920, 1080, 2]];

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

async function login(ctx, kind, who) {
  const page = await ctx.newPage();
  await page.goto(BASE + '/login' + (kind === 'member' ? '?tab=member' : ''), { waitUntil: 'domcontentloaded' });
  let target = who;
  if (kind === 'member') {
    target = await page.evaluate(() => { try { return JSON.parse(document.getElementById('app').dataset.page).props.memberDemoAccounts[0].n; } catch (e) { return 1; } });
  }
  const ok = await page.evaluate(async (kind, target) => {
    const tok = decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || '');
    const r = await fetch(kind === 'member' ? '/demo-member-login/' + target : '/demo-login/' + target, { method: 'POST', headers: { 'X-XSRF-TOKEN': tok, 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin', redirect: 'follow' });
    return r.status;
  }, kind, target);
  await page.close();
  return ok;
}

async function waitReady(page) {
  for (let i = 0; i < 40; i++) { if (await page.evaluate(() => !!document.querySelector('h1') || document.title.includes('Not Found') ).catch(() => false)) break; await sleep(250); }
  await sleep(900);
}

async function runAudit(page) {
  return page.evaluate(() => window.__uiAudit()).catch((e) => ({ error: String(e).slice(0, 200) }));
}

function slim(r) {
  if (!r || r.error) return r;
  const samples = {};
  for (const [k, v] of Object.entries(r.samples || {})) samples[k] = v.slice(0, 3);
  return { critical: r.critical, advisory: r.advisory, counts: r.counts, samples, metrics: r.metrics };
}

async function auditRoute(ctx, kind, role, route, sink) {
  const modes = [['phone', MATRIX.phone, { isMobile: true, hasTouch: true }], ['tablet', MATRIX.tablet, { isMobile: false, hasTouch: true }], ['desktop', MATRIX.desktop, { isMobile: false, hasTouch: false }]];
  for (const [mode, widths, flags] of modes) {
    const page = await ctx.newPage();
    const errs = [];
    page.on('pageerror', (e) => errs.push(String(e).slice(0, 120)));
    let status = 0, finalUrl = route;
    try {
      const [w0, h0] = widths[0];
      await page.setViewport({ width: w0, height: h0, deviceScaleFactor: 1, ...flags });
      const resp = await page.goto(BASE + route, { waitUntil: 'domcontentloaded', timeout: 30000 });
      status = resp ? resp.status() : 0;
      await waitReady(page);
      await page.evaluate(AUDIT);
      finalUrl = new URL(page.url()).pathname;
      const list = widths.map(([w, h]) => ({ name: w + 'x' + h, w, h, dpr: 1 }));
      if (mode === 'desktop') {
        for (const [w, h, z] of ZOOMS) list.push({ name: 'zoom' + z * 100 + '@' + w, w: Math.round(w / z), h: Math.round(h / z), dpr: z });
        list.push({ name: 'text200@1280', w: 1280, h: 720, dpr: 1, text: 2 });
      }
      for (const v of list) {
        await page.setViewport({ width: v.w, height: v.h, deviceScaleFactor: v.dpr, ...flags });
        if (v.text) await page.evaluate((t) => { document.documentElement.style.fontSize = t * 100 + '%'; }, v.text);
        await page.evaluate(() => new Promise((r) => requestAnimationFrame(() => requestAnimationFrame(() => setTimeout(r, 80))))).catch(() => {});
        const rep = await runAudit(page);
        if (v.text) await page.evaluate(() => { document.documentElement.style.fontSize = ''; });
        sink({ kind, role, route, mode, vp: v.name, width: v.w, height: v.h, dpr: v.dpr, status, finalUrl, errs: errs.slice(0, 2), report: slim(rep) });
      }
    } catch (e) {
      sink({ kind, role, route, mode, vp: 'fail', status, finalUrl, error: String(e).slice(0, 200) });
    }
    await page.close().catch(() => {});
  }
}

async function pool(items, n, fn) {
  let i = 0;
  await Promise.all(Array.from({ length: n }, async () => { while (i < items.length) { const it = items[i++]; await fn(it); } }));
}

(async () => {
  const RESUME = process.argv.includes('--resume');
  const open = (kind) => fs.createWriteStream(path.join(OUT, kind + '.jsonl'), { flags: RESUME ? 'a' : 'w' });
  const lim = (a) => (LIMIT ? a.slice(0, LIMIT) : a);

  // one browser per worker: tabs of a single headless browser are throttled as background pages
  async function group(kind, role, list, who) {
    const ws = open(kind + '-' + role);
    const sink = (o) => ws.write(JSON.stringify(o) + '\n');
    const have = {};
    if (RESUME && fs.existsSync(path.join(OUT, kind + '-' + role + '.jsonl'))) for (const l of fs.readFileSync(path.join(OUT, kind + '-' + role + '.jsonl'), 'utf8').split('\n')) { try { const o = JSON.parse(l); if (o.vp !== 'fail') have[o.route] = (have[o.route] || 0) + 1; } catch (e) { /* skip */ } }
    const items = lim(list).filter((r) => !(have[r] >= 25)).slice();
    let done = 0;
    await Promise.all(Array.from({ length: CONC }, async (_, k) => {
      const br = await puppeteer.launch({ executablePath: CHROME, headless: true, protocolTimeout: 180000, args: ['--no-sandbox', '--disable-gpu'] });
      const ctx = await br.createBrowserContext();
      if (who) { const st = await login(ctx, kind === 'member' ? 'member' : 'staff', who); if (k === 0) console.log(kind, role, 'login', st); }
      while (items.length) { const r = items.shift(); try { await auditRoute(ctx, kind, role, r, sink); } catch (e) { sink({ kind, role, route: r, vp: 'fail', error: String(e).slice(0, 160) }); } done++; if (done % 5 === 0) console.log(kind, role, done + '/' + list.length, new Date().toISOString().slice(11, 19)); }
      await br.close();
    }));
    ws.end();
  }

  if (['all', 'public'].includes(ONLY)) await group('public', 'guest', [...routes.public, ...routes.authPages, '/careers/opes-demo/1']);
  if (['all', 'member'].includes(ONLY)) await group('member', 'member', routes.member, 1);
  if (['all', 'errors'].includes(ONLY)) {
    await group('errors', 'guest', ['/this-page-does-not-exist', '/dashboard']);
    await group('errors', 'cashier', ['/finance', '/hr', '/this-page-does-not-exist'], 'cashier');
  }
  if (['all', 'staff'].includes(ONLY)) await group('staff', 'gm', routes.staff, 'gm');
  console.log('done');
})().catch((e) => { console.error(e); process.exit(1); });
