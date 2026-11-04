Small, local fixes made by R1 (all verified with a fresh load at the width that failed, then the affected routes re-run in the full matrix; build `bash scripts/build-locked.sh` green):

| File | Defect (harness rule) | Fix |
|---|---|---|
| `resources/js/Pages/Landing/Index.tsx` | `/` horizontal overflow of 34 px at 1024 px: header nav links plus actions did not fit (overflow-x, P1) | Header nav shows from `xl` (1280) instead of `lg` (1024) |
| `resources/js/Pages/Finance/Consolidation.tsx` | `/finance/consolidation` overflow of 11 px at 320 px: the three view buttons did not wrap (overflow-x, P1) | `flex-wrap` on the button row |
| `resources/js/Pages/HR/TalentSkills.tsx` | five selects and the issue-date input without an accessible name (no-label, P1) | `aria-label` from existing `talent.*` keys; new key `ui2.talent.issued_on` in `lang/{en,fr}/ui2.json` |
| `resources/js/Pages/HR/TalentCompliance.tsx` | two selects (payroll run, employee) without a name (no-label, P1) | `aria-label` from `talent.choose_run` and `talent.employee` |
| `resources/js/Pages/Billing/Folios.tsx` | folio number button 36 px high on touch widths (touch-target, P2) | `min-h-11 md:min-h-9` |
| `resources/js/Components/ui/Toast.tsx` (shared, tiny) | toast dismiss button 32 x 32 px (touch-target, P2) | added the shared `hit-44` class |
| `scripts/ui-audit/audit.js` | harness false positives found while triaging: password reveal button flagged as overlap, window-scroll layouts not scrolled for covered-by-fixed, top-sticky headers reported as covering, `pointer-events:none` plus `opacity` controls reported for contrast, chart legend icon reported as a narrow chart, 50 percent radius, wrapped button labels reported as height variants | harness refined; `register.cjs` also drops findings that do not reproduce on a fresh load (`recheck.cjs`) |
