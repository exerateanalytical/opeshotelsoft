// Rebuilds scripts/ui-audit/routes.json from `php artisan route:list --json --method=GET`.
// Usage: php artisan route:list --json --method=GET > scripts/ui-audit/.routes.tmp.json && node scripts/ui-audit/build-routes.cjs
const fs = require('fs');
const list = JSON.parse(fs.readFileSync(__dirname + '/.routes.tmp.json', 'utf8'));
const SAMPLE = { group: 'opes-demo', id: '1', number: '1', card: '1', member: '1', reservation: '1', post: '1', token: 'sample-token', code: 'sample', sig: 'sample' };
const skip = /^(api\/|_|up$|storage|sanctum|livewire|broadcasting|webhooks|oauth|health|docs\/|\{fallback|g\/order|pay\/|sign\/|careers\/offer|two-factor|forgot)/;
const nonPage = /(\/pdf$|\/kot$|\/messages$|\/statement$)/;
const publicRe = /^(book\/|careers\/|verify\/)|^$/;
const out = { staff: [], public: [], member: [], authPages: ['/login', '/forgot-password'], note: 'Staff routes need a signed-in general manager. Routes with {param} use the sample values in `sample`; replace with real ids when a page 404s. Pages with no Inertia component (PDF, JSON) are excluded.' , sample: SAMPLE };
for (const r of list) {
  const uri = r.uri === '/' ? '' : r.uri;
  if (skip.test(uri) || nonPage.test(uri)) continue;
  const path = '/' + uri.replace(/\{(\w+)\??\}/g, (_, k) => SAMPLE[k] || '1');
  if (uri === '') out.public.push('/');
  else if (uri === 'login') continue;
  else if (uri.startsWith('account')) out.member.push(path);
  else if (publicRe.test(uri)) out.public.push(path);
  else out.staff.push(path);
}
for (const k of ['staff', 'public', 'member']) out[k] = [...new Set(out[k])].sort();
fs.writeFileSync(__dirname + '/routes.json', JSON.stringify(out, null, 2) + '\n');
console.log(Object.fromEntries(['staff', 'public', 'member'].map((k) => [k, out[k].length])));
