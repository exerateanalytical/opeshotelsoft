// One-off codemod: pale text-slate-400 (2.6:1 on white) becomes text-slate-500 (muted token, AA).
// Skips dark-surface files and lines that declare a dark background. Safe to re-run.
const fs = require('fs'), path = require('path');
const skip = new Set(['Auth/Login.tsx','Auth/MemberAuthPanel.tsx','Auth/TwoFactorChallenge.tsx','Landing/Index.tsx','Components/auth/DemoSelect.tsx','Components/layout/Sidebar.tsx','Components/layout/Topbar.tsx','Components/voice/VoiceNotifier.tsx']);
let files = 0, n = 0; const kept = [];
function walk(d) {
  for (const f of fs.readdirSync(d, { withFileTypes: true })) {
    const p = path.join(d, f.name);
    if (f.isDirectory()) { walk(p); continue; }
    if (!/\.tsx?$/.test(f.name)) continue;
    const rel = p.split(path.sep).join('/').replace(/.*resources\/js\/(Pages\/)?/, '');
    if (skip.has(rel)) continue;
    const lines = fs.readFileSync(p, 'utf8').split('\n'); let ch = false;
    for (let i = 0; i < lines.length; i++) {
      if (!lines[i].includes('text-slate-400')) continue;
      if (/bg-navy|bg-slate-(800|900|950)|bg-\[#0|bg-black|bg-gray-9/.test(lines[i])) { kept.push(rel + ':' + (i + 1)); continue; }
      n += (lines[i].match(/text-slate-400/g) || []).length;
      lines[i] = lines[i].replace(/text-slate-400/g, 'text-slate-500'); ch = true;
    }
    if (ch) { fs.writeFileSync(p, lines.join('\n')); files++; }
  }
}
walk('resources/js');
console.log('files', files, 'replacements', n, 'kept(dark)', kept.join(' '));
