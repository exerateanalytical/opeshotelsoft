/*
 * Device-class audit and guard (DV1). Renders every route in routes.json per device class and checks that each
 * viewport gets the UI of its class: phones never get the sidebar or desktop tables, desktops never get the bottom
 * tab bar, a hidden sidebar, phone filters or a narrow centred column, and nothing overflows horizontally.
 *   PUPPETEER_CORE=<folder with node_modules/puppeteer-core> node scripts/ui-audit/device.cjs --base http://localhost:8131 [--only staff|public|member] [--limit N] [--conc 3] [--tag before]
 * Exit code 1 when any wrong-device finding is recorded (use it as the guard). Results: results/device-<tag>.json
 */
const fs = require('fs');
const path = require('path');
const { createRequire } = require('module');
const req = createRequire(process.env.PUPPETEER_CORE ? path.join(process.env.PUPPETEER_CORE, 'x.js') : __filename);
const puppeteer = req('puppeteer-core');

const arg = (k, d) => { const i = process.argv.indexOf('--' + k); return i > -1 ? process.argv[i + 1] : d; };
const BASE = arg('base', 'http://localhost:8131');
const ONLY = arg('only', 'all');
const LIMIT = parseInt(arg('limit', '0'), 10);
const CONC = parseInt(arg('conc', '3'), 10);
const TAG = arg('tag', 'latest');
const CHROME = process.env.CHROME_PATH || 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const routes = JSON.parse(fs.readFileSync(path.join(__dirname, 'routes.json'), 'utf8'));
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

// [name, css width, css height, deviceScaleFactor, expected class]
const MODES = {
  phone: { flags: { isMobile: true, hasTouch: true }, vps: [['360', 360, 740, 3, 'phone'], ['390', 390, 844, 3, 'phone'], ['412', 412, 915, 2.6, 'phone']] },
  tablet: { flags: { isMobile: false, hasTouch: true }, vps: [['768', 768, 1024, 2, 'tablet'], ['820', 820, 1180, 2, 'tablet'], ['1024', 1024, 768, 2, 'tablet']] },
  desktop: { flags: { isMobile: false, hasTouch: false }, vps: [['1280', 1280, 720, 1, 'desktop'], ['1366', 1366, 768, 1, 'desktop'], ['1536@1.25', 1536, 864, 1.25, 'desktop'], ['1920', 1920, 1080, 1, 'desktop'], ['1366@125%', 1093, 614, 1.25, 'desktop'], ['1366@150%', 911, 512, 1.5, 'desktop']] },
};

/* Runs in the page. Reports what kind of UI is on screen. */
function probe() {
  const vis = (el) => { if (!el) return false; const r = el.getBoundingClientRect(); const cs = getComputedStyle(el); return r.width > 1 && r.height > 1 && cs.display !== 'none' && cs.visibility !== 'hidden'; };
  const W = window.innerWidth;
  // bottom tab bar: the staff BottomNav, or any fixed bar at the bottom edge holding 3+ links/buttons
  let tabBar = [...document.querySelectorAll('.tab-bar-item')].some(vis);
  if (!tabBar) for (const el of document.querySelectorAll('nav, [role="navigation"], div, footer')) {
    const cs = getComputedStyle(el); if (cs.position !== 'fixed' && cs.position !== 'sticky') continue;
    const r = el.getBoundingClientRect(); if (!vis(el) || r.height > 120 || r.bottom < window.innerHeight - 2 || r.width < W * 0.8) continue;
    if (el.querySelectorAll('a,button').length >= 3) { tabBar = true; break; }
  }
  const aside = document.querySelector('aside[data-surface="dark"]');
  const sidebarW = vis(aside) ? Math.round(aside.getBoundingClientRect().width) : 0;
  const main = document.getElementById('main') || document.querySelector('main') || document.body;
  let tablesHead = 0, tablesCards = 0;
  for (const tb of main.querySelectorAll('table')) {
    if (!vis(tb) || tb.closest('.l1-sr-only, .sr-only')) continue; const th = tb.querySelector('thead');
    if (th && vis(th) && th.getBoundingClientRect().width > 2) tablesHead++; else if (tb.classList.contains('table-cards')) tablesCards++;
  }
  const filterSheetBtn = [...main.querySelectorAll('button')].some((b) => vis(b) && b.querySelector('svg.lucide-sliders-horizontal') && !b.closest('[role="search"]'));
  const mr = main.getBoundingClientRect();
  let minL = Infinity, maxR = -Infinity;
  for (const el of main.querySelectorAll('h1,h2,h3,p,table,form,input,select,button,a,img,canvas,svg,[class*="card"],li')) {
    if (!vis(el)) continue; const r = el.getBoundingClientRect(); if (r.width < 4) continue;
    minL = Math.min(minL, r.left); maxR = Math.max(maxR, r.right);
  }
  const contentW = maxR > minL ? Math.round(maxR - minL) : 0;
  const docOverflow = document.documentElement.scrollWidth - W;
  const mainOverflow = main.scrollWidth - main.clientWidth;
  return {
    device: document.documentElement.dataset.device || null, density: document.documentElement.dataset.density || null,
    tabBar, sidebarW, tablesHead, tablesCards, filterSheetBtn, mainW: Math.round(mr.width), contentW,
    overflow: Math.max(docOverflow, mainOverflow), h1: !!document.querySelector('h1'), url: location.pathname,
  };
}

function judge(p, expect, staff) {
  const f = [];
  if (p.device && p.device !== expect) f.push('device=' + p.device + ' expected ' + expect);
  if (expect === 'desktop') {
    if (p.tabBar) f.push('bottom tab bar on desktop');
    if (staff && p.sidebarW < 180) f.push(p.sidebarW ? 'icon-only rail on desktop' : 'sidebar hidden on desktop');
    if (p.tablesCards) f.push('card list instead of table');
    if (p.filterSheetBtn) f.push('filters collapsed into a sheet');
    // sign-in cards are narrow by design; the check targets the staff app
    // content-sized by nature: an org tree, and a single payment record (demo data has no payment 1: empty state)
    if (staff && !['/hr/org-chart', '/payments/1'].includes(p.url) && p.mainW >= 900 && p.contentW && p.contentW < Math.min(560, p.mainW * 0.5)) f.push('narrow centred column (' + p.contentW + 'px of ' + p.mainW + ')');
  } else if (expect === 'phone') {
    if (p.sidebarW) f.push('sidebar on phone');
    if (p.tablesHead) f.push('desktop table on phone');
  } else if (expect === 'tablet') {
    if (p.tabBar && staff) f.push('bottom tab bar on tablet');
    if (staff && p.sidebarW > 100) f.push('full sidebar on touch tablet');
  }
  if (p.overflow > 1) f.push('horizontal overflow ' + p.overflow + 'px');
  return f;
}

async function login(ctx, kind, who) {
  const page = await ctx.newPage();
  await page.goto(BASE + '/login' + (kind === 'member' ? '?tab=member' : ''), { waitUntil: 'domcontentloaded', timeout: 120000 });
  let target = who;
  if (kind === 'member') target = await page.evaluate(() => { try { return JSON.parse(document.getElementById('app').dataset.page).props.memberDemoAccounts[0].n; } catch (e) { return 1; } });
  const st = await page.evaluate(async (kind, target) => {
    const tok = decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || '');
    const r = await fetch(kind === 'member' ? '/demo-member-login/' + target : '/demo-login/' + target, { method: 'POST', headers: { 'X-XSRF-TOKEN': tok, 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' });
    return r.status;
  }, kind, target);
  await page.close();
  return st;
}

async function auditRoute(ctx, kind, route, out) {
  const only = arg('modes', '') ? arg('modes', '').split(',') : null; // e.g. --modes desktop,phone
  for (const [mode, { flags, vps }] of Object.entries(MODES)) {
    if (only && !only.includes(mode)) continue;
    let page = null;
    try {
      page = await ctx.newPage();
      const [, w0, h0, d0] = vps[0];
      await page.setViewport({ width: w0, height: h0, deviceScaleFactor: d0, ...flags });
      await page.goto(BASE + route, { waitUntil: 'domcontentloaded', timeout: 90000 });
      for (let i = 0; i < 40; i++) { if (await page.evaluate(() => !!document.querySelector('h1')).catch(() => false)) break; await sleep(250); }
      await sleep(700);
      for (const [name, w, h, dpr, expect] of vps) {
        await page.setViewport({ width: w, height: h, deviceScaleFactor: dpr, ...flags });
        await page.evaluate(() => new Promise((r) => requestAnimationFrame(() => requestAnimationFrame(() => setTimeout(r, 120))))).catch(() => {});
        const p = await page.evaluate(probe);
        out.push({ kind, route, mode, vp: name, expect, probe: p, findings: judge(p, expect, kind === 'staff') });
      }
    } catch (e) {
      out.push({ kind, route, mode, vp: 'fail', error: String(e).slice(0, 160), findings: [] });
    }
    if (page) await page.close().catch(() => {});
  }
}

(async () => {
  const out = [];
  const lim = (a) => (LIMIT ? a.slice(0, LIMIT) : a);
  async function group(kind, list, who) {
    const items = lim(list).slice();
    let done = 0;
    await Promise.all(Array.from({ length: CONC }, async () => {
      const br = await puppeteer.launch({ executablePath: CHROME, headless: true, protocolTimeout: 180000, args: ['--no-sandbox', '--disable-gpu'] });
      const ctx = await br.createBrowserContext();
      if (who) { for (let t = 0; t < 3; t++) { try { await login(ctx, kind === 'member' ? 'member' : 'staff', who); break; } catch (e) { console.log('login retry', String(e).slice(0, 80)); } } }
      while (items.length) { const r = items.shift(); try { await auditRoute(ctx, kind, r, out); } catch (e) { out.push({ kind, route: r, vp: 'fail', error: String(e).slice(0, 160), findings: [] }); } if (++done % 10 === 0) console.log(kind, done + '/' + list.length); }
      await br.close().catch(() => {}); // Windows sometimes keeps the profile lockfile busy
    }));
  }
  if (['all', 'public'].includes(ONLY)) await group('public', [...routes.public, ...routes.authPages]);
  if (['all', 'member'].includes(ONLY)) await group('member', routes.member, 1);
  if (['all', 'staff'].includes(ONLY)) await group('staff', routes.staff, 'gm');
  out.sort((a, b) => (a.kind + a.route + a.mode).localeCompare(b.kind + b.route + b.mode));
  fs.mkdirSync(path.join(__dirname, 'results'), { recursive: true });
  fs.writeFileSync(path.join(__dirname, 'results', 'device-' + TAG + '.json'), JSON.stringify(out, null, 1));
  const bad = out.filter((o) => o.findings.length);
  const routesSeen = new Set(out.map((o) => o.route)).size;
  console.log('routes', routesSeen, 'viewports', out.length, 'with findings', bad.length, 'fails', out.filter((o) => o.vp === 'fail').length);
  for (const b of bad.slice(0, 400)) console.log(b.kind, b.route, b.vp, '|', b.findings.join('; '));
  process.exit(bad.length ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(2); });
