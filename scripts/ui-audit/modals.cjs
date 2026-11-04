/*
 * Modal and sheet usability check on the most important staff pages (workstream R1).
 *   node scripts/ui-audit/modals.cjs [--base http://localhost:8120]
 * Opens the first few "new / add / create / edit" buttons of each page at 320, 375 and 1280 px and checks: role=dialog,
 * fits the viewport (width, height), internal scroll, footer buttons reachable, close button, Escape closes, focus moves
 * into the dialog, Tab stays trapped. Output: scripts/ui-audit/results/raw/modals.jsonl
 */
const fs = require('fs');
const path = require('path');
const { createRequire } = require('module');
const req = createRequire(process.env.PUPPETEER_CORE ? path.join(process.env.PUPPETEER_CORE, 'x.js') : __filename);
const puppeteer = req('puppeteer-core');
const arg = (k, d) => { const i = process.argv.indexOf('--' + k); return i > -1 ? process.argv[i + 1] : d; };
const BASE = arg('base', 'http://localhost:8120');
const CHROME = process.env.CHROME_PATH || 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const OUT = path.join(__dirname, 'results', 'raw', 'modals.jsonl');
const PAGES = ['/dashboard', '/reservations', '/reservations/create', '/reservations/tape-chart', '/reservations/waitlist', '/front-desk', '/front-desk/in-house', '/billing/folios', '/billing/cashier', '/guests', '/guests/1', '/housekeeping', '/maintenance', '/pos', '/stock', '/stock/issues', '/hr', '/hr/workforce/leave', '/finance', '/finance/budgets', '/loyalty', '/loyalty/members', '/groups', '/settings/users', '/payments', '/messaging', '/service-requests', '/properties', '/reservations/seasons'];
const VPS = [[320, 640, true], [375, 812, true], [1280, 720, false]];
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const TRIGGER = /^(\+|new|add|create|nouveau|nouvelle|ajouter|cr[ée]er|edit|modifier|record|enregistrer|register|issue|filters?|filtres?|assign|invite|import|schedule|book|reserve|log|adjust|configure|manage|details?|view|open|quick|more|rooming|walk|room|rate|guest|template|rule)/i;

const findDialog = () => {
  const d = document.querySelector('[role=dialog],[role=alertdialog]');
  if (d) return d;
  return Array.from(document.querySelectorAll('div')).find((e) => { const s = getComputedStyle(e); return s.position === 'fixed' && e.getBoundingClientRect().width >= innerWidth - 2 && e.getBoundingClientRect().height >= innerHeight - 2 && /inset-0|overlay|backdrop/.test(e.className || '') && !e.closest('header,nav,aside'); }) || null;
};

(async () => {
  const browser = await puppeteer.launch({ executablePath: CHROME, headless: true, args: ['--no-sandbox', '--disable-gpu'] });
  const ctx = await browser.createBrowserContext();
  const lp = await ctx.newPage();
  await lp.goto(BASE + '/login', { waitUntil: 'domcontentloaded' });
  await lp.evaluate(async () => { const tok = decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || ''); await fetch('/demo-login/gm', { method: 'POST', headers: { 'X-XSRF-TOKEN': tok } }); });
  await lp.close();
  const ws = fs.createWriteStream(OUT);
  for (const route of PAGES) {
    for (const [w, h, mobile] of VPS) {
      const page = await ctx.newPage();
      await page.setViewport({ width: w, height: h, isMobile: mobile, hasTouch: mobile });
      try {
        await page.goto(BASE + route, { waitUntil: 'domcontentloaded', timeout: 30000 });
        for (let i = 0; i < 30 && !(await page.$('h1')); i++) await sleep(250);
        await sleep(900);
        const labels = await page.evaluate((src) => {
          const re = new RegExp(src, 'i');
          const seen = new Set();
          return Array.from(document.querySelectorAll('main button, main a[role=button], [role=main] button, header button')).filter((b) => { const r = b.getBoundingClientRect(); const t = (b.innerText || b.getAttribute('aria-label') || '').trim(); if (!t || r.width < 1 || seen.has(t) || b.type === 'submit' || b.disabled) return false; if (re.test(t)) { seen.add(t); return true; } return false; })
          .slice(0, 5).map((b) => (b.innerText || b.getAttribute('aria-label')).trim());
        }, TRIGGER.source);
        if (!labels.length) { ws.write(JSON.stringify({ route, w, trigger: null, note: 'no modal trigger found' }) + '\n'); await page.close(); continue; }
        for (const label of labels) {
          const rec = { route, w, h, trigger: label };
          try {
            const clicked = await page.evaluate((label) => { const b = Array.from(document.querySelectorAll('main button, main a[role=button], [role=main] button, header button')).find((x) => (x.innerText || x.getAttribute('aria-label') || '').trim() === label); if (!b) return false; b.scrollIntoView({ block: 'center' }); b.click(); return true; }, label);
            await sleep(700);
            await page.evaluate('window.__fd = ' + findDialog.toString());
            const info = await page.evaluate(() => {
              const d = window.__fd();
              if (!d) return { opened: false };
              const panel = d.matches('[role=dialog]') ? d : (d.querySelector('[role=dialog]') || d);
              const r = panel.getBoundingClientRect();
              const body = Array.from(panel.querySelectorAll('*')).find((e) => /auto|scroll/.test(getComputedStyle(e).overflowY) && e.scrollHeight > e.clientHeight + 1);
              const btns = Array.from(panel.querySelectorAll('button,a[href]')).filter((b) => b.getBoundingClientRect().width > 0);
              const last = btns[btns.length - 1];
              const lr = last ? last.getBoundingClientRect() : null;
              const close = btns.find((b) => /close|fermer|cancel|annuler/i.test((b.getAttribute('aria-label') || '') + ' ' + (b.innerText || '')));
              return {
                opened: true, semantic: panel.matches('[role=dialog],[role=alertdialog]'), modal: panel.getAttribute('aria-modal') === 'true', labelled: !!(panel.getAttribute('aria-labelledby') || panel.getAttribute('aria-label')),
                rect: [Math.round(r.left), Math.round(r.top), Math.round(r.width), Math.round(r.height)], fitsW: r.left >= -1 && r.right <= innerWidth + 1, fitsH: r.top >= -1 && r.bottom <= innerHeight + 1,
                scrolls: !!body, footerReachable: lr ? lr.bottom <= innerHeight + 1 && lr.top >= 0 : null, hasClose: !!close, focusInside: panel.contains(document.activeElement),
                fields: panel.querySelectorAll('input:not([type=hidden]),select,textarea').length,
              };
            });
            Object.assign(rec, info, { clicked });
            if (info.opened) {
              let escaped = true;
              for (let i = 0; i < 30; i++) { await page.keyboard.press('Tab'); const inside = await page.evaluate(() => { const d = window.__fd(); if (!d) return true; const p = d.matches('[role=dialog]') ? d : (d.querySelector('[role=dialog]') || d); return p.contains(document.activeElement); }).catch(() => true); if (!inside) { escaped = false; break; } }
              rec.tabTrapped = escaped;
              await page.keyboard.press('Escape'); await sleep(400);
              rec.escapeCloses = !(await page.evaluate(() => !!window.__fd()).catch(() => false));
            }
          } catch (e) { rec.error = String(e).slice(0, 160); }
          ws.write(JSON.stringify(rec) + '\n');
          // make sure the next trigger starts clean
          await page.keyboard.press('Escape').catch(() => {});
          await sleep(200);
        }
      } catch (e) { ws.write(JSON.stringify({ route, w, error: String(e).slice(0, 160) }) + '\n'); }
      await page.close().catch(() => {});
    }
    console.log('modals', route);
  }
  ws.end();
  await browser.close();
})().catch((e) => { console.error(e); process.exit(1); });
