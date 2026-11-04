# Overnight plan (started 2026-11-04, owner asleep)

Goal: wake up to a complete and functional platform. Owner instructions: proceed per the recommendations, do not let blockers stop the work, delegate small jobs to cheaper models (haiku, sonnet) and complex jobs to stronger models (opus, fable), coordinate everything. Standing rules: at most 4 agents at once, flat UI with no glow, readable fonts, role-based access enforced on the server, never touch other projects' databases, each agent uses its own test database and port, one test command at a time per database, edits with Edit/Write tools (never sed on code), builds only with `bash scripts/build-locked.sh`.

## Decisions taken for the owner (defaults, reversible)
- HR officer and the Pay tab of the employee profile: stays hidden from hr-officer (least privilege). Payroll officer, finance director and general manager see it. To change: grant `payroll.view` to hr-officer in database/seeders/permissions.
- Old accounting paths that skip attachments and four-eyes: harden them without breaking existing tests (permission check, audit trail, reopen requires a reason); the new `core/entries` path remains the recommended one.
- Public multi-room booking: deferred (needs its own project); staff group booking exists.
- Orange Money salary disbursement endpoints are assumptions: stay in dry-run until verified against the contract.
- No push of secrets; `.env` stays ignored.

## Model assignment
- opus or fable (complex, shared files, security, cross-module): performance, typography and charts pass, security audit, accounting hardening, final verification and fixes of real test failures.
- sonnet (moderate, well-specified, mechanical across many files): states sweep, register findings fixes, document and loyalty small fixes.
- haiku (small, low risk): housekeeping (list and drop this project's scratch test databases, remove leftover temp files), documentation index and BLOCKERS refresh.

## Status checklist (update as work completes)
- [x] Phase 1 to 4 features, document engine, member portal, landing, brand, UI standard (committed c33a4fa)
- [x] Stock forms, reservation settings screens, scheduled jobs, PDF QA, QA sweep, booking identity, staff home, desktop-native feel, states system (uncommitted at the time of writing)
- [ ] Re-run of Documents, HR, Reservations folders (script scratchpad/rerun3.sh, marker rr_DONE)
- [ ] Extended audit R1 report (docs/UI-REGISTER.md, UI-AUDIT-FINAL.md)
- [ ] Commit and push
- [ ] Platform loading speed (owner: the platform feels sluggish; part of the goal): measure first, then fix backend boot, queries, payloads, bundle, caching; docs/PERFORMANCE.md
- [ ] Typography and charts pass
- [x] States sweep for the remaining 76 pages: done by ST1 and ST2 (140 of 143 complete; 3 false positives)
- [ ] Register findings fixed
- [ ] Security audit (skill security-audit) and fixes of critical and high findings: SEC1 (fable) started; artifacts in docs/security-audit/
- [ ] Accounting hardening
- [ ] Document minor fixes (English words in French documents, empty facts line, transfer letter without position) and loyalty gaps (stay credits, hotel photos, number formats by language)
- [ ] Housekeeping: scratch databases, temp files, docs index
- [ ] Final full test run, fresh install check, build, tsc, browser smoke
- [ ] docs/MORNING-REPORT.md written, final commit and push

## If this session is interrupted
Read this file, `git status`, the scratchpad result files t1_*.txt and rr_*.txt, then continue from the first unchecked item. Never start more than 4 agents at once.

## Log
- Stock valuation 'date in the future' bug fixed by the lead (hotel calendar date allowed when the business date lags); test run on DB opeshotelsoft_test_lead2.
- Running at last update: R1 extended audit, L1 typography and charts, P1 speed, SEC1 security.
