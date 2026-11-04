# UI tokens inventory (R1)

Generated 2026-11-04 by `node scripts/ui-audit/static.cjs`. Source of truth: `resources/css/app.css` (`@theme` for Tailwind, `:root` for semantic `--c-*`). Contrast pairs are declared as `ui-pair:` comments and checked by `php scripts/ui-audit/contrast.php`.

## Inventory in app.css

| Family | Count | Names (first 12) |
|---|---|---|
| Brand and surface palette | 30 | `--color-brand-50` `--color-brand-100` `--color-brand-200` `--color-brand-300` `--color-brand-400` `--color-brand-500` `--color-brand-600` `--color-brand-700` `--color-brand-800` `--color-brand-900` `--color-navy-50` `--color-navy-100` |
| Status colours | 4 | `--color-success` `--color-warning` `--color-error` `--color-info` |
| Palette overrides | 6 | `--color-slate-500` `--color-emerald-600` `--color-amber-500` `--color-amber-600` `--color-red-500` `--color-green-600` |
| Semantic colours (--c-*) | 34 | `--c-text-primary` `--c-text-secondary` `--c-text-muted` `--c-surface` `--c-surface-subtle` `--c-surface-page` `--c-border` `--c-border-control` `--c-accent` `--c-on-accent` `--c-accent-text` `--c-primary` |
| Type scale | 15 | `--font-sans` `--text-xs` `--text-xs--line-height` `--text-sm` `--text-sm--line-height` `--text-base` `--text-base--line-height` `--text-lg` `--text-lg--line-height` `--text-xl` `--text-xl--line-height` `--text-2xl` |
| Radius | 5 | `--radius-sm` `--radius-md` `--radius-lg` `--radius-xl` `--radius-2xl` |
| Elevation, blur (all neutralised) | 9 | `--shadow-2xs` `--shadow-lg` `--inset-shadow-2xs` `--drop-shadow-xs` `--drop-shadow-lg` `--text-shadow-2xs` `--text-shadow-md` `--blur-xs` `--blur-xl` |
| z-index | 7 | `--z-base` `--z-sticky` `--z-bar` `--z-dropdown` `--z-sheet` `--z-modal` `--z-toast` |
| Other | 35 | `--bar-top-h` `--bar-bottom-h` `--safe-top` `--safe-bottom` `--safe-left` `--safe-right` `--type-body` `--type-support` `--type-caption` `--type-table` `--type-section` `--type-page` |

Total custom properties: 143. Spacing uses Tailwind's 4 px scale (no custom spacing tokens); breakpoints are Tailwind's (sm 640, md 768, lg 1024, xl 1280, 2xl 1536) plus the shell's 768 and 1024 JS thresholds in `lib/useMediaQuery`.

### Gaps against the directive (rule 41)
- No spacing token set (scale 4, 8, 12, 16, 24, 32, 48 is a convention, not enforced). 268 utilities use off-scale steps (5 114, 0.5 61, 1.5 37, 10 23, 9 16, 2.5 15, 7 2).
- No line-height or letter-spacing tokens beyond the `--text-*--line-height` pairs; 23 `uppercase` and 8 `tracking-*` uses are ad hoc.
- No `--bp-*` tokens (breakpoints live in Tailwind and in JS).
- z-index: 13 raw numeric `z-NN`/`z-[NN]` usages in components next to the token scale (see below).
- No max-content-width token: wide-screen behaviour is per-page (see UI-AUDIT-FINAL, ultra-wide findings).

## Hard-coded values outside the tokens

### In app.css (outside :root and @theme)
- Hex colours: 3 lines (214, 348, 349)
- Fixed z-index numbers: 2 lines (545, 546)
- Fixed px sizes for font-size, height, width, radius: 41 lines (187, 212, 214, 253, 262, 267, 286, 287, 288, 292, 293, 299, 303, 305, 307, 314, 317, 322, 323, 329)

### In resources/js (counts per file)

| Kind | Total | Top files |
|---|---|---|
| Hex colour literals (#rrggbb) | 37 | `Pages/Verify/Show.tsx` 18; `app.tsx` 6; `Pages/Onboarding/Branding.tsx` 6; `Pages/HR/EmployeeProfileSections.tsx` 2; `Pages/Careers/Index.tsx` 1; `Pages/Careers/Offer.tsx` 1; `Pages/FrontDesk/Partials/StayPanels.tsx` 1; `Pages/HR/SignaturePad.tsx` 1; `Pages/Reservations/SignaturePad.tsx` 1 |
| `rgb()/rgba()` literals | 0 | none |
| `style={{ color/background/border }}` | 15 | `Pages/Verify/Show.tsx` 12; `Pages/Onboarding/Branding.tsx` 3 |
| Arbitrary colour utilities `bg-[#..]`, `text-[var(--c-..)]` | 28 (of which `var(--c-*)`, i.e. token based: 26) | `Components/layout/Sidebar.tsx` 13; `Components/layout/Topbar.tsx` 9; `Components/layout/BottomNav.tsx` 2; `Layouts/AppLayout.tsx` 1; `Layouts/PublicErrorLayout.tsx` 1; `Pages/Careers/Index.tsx` 1; `Pages/Careers/Offer.tsx` 1 |
| Non-brand Tailwind hues (red, amber, emerald, green, blue, orange, yellow, rose, purple, ...) | 680 | red 308, amber 136, emerald 158, blue 35, yellow 19, sky 7, green 7, violet 2, orange 3, indigo 5 |
| `text-slate-300/400` (low contrast on light surfaces) | 33 | `Pages/Auth/Login.tsx` 12; `Pages/Auth/MemberAuthPanel.tsx` 10; `Pages/Member/Card.tsx` 3; `Pages/Member/Home.tsx` 3; `Pages/Auth/TwoFactorChallenge.tsx` 2; `Components/auth/DemoSelect.tsx` 1; `Pages/Auth/ForgotPassword.tsx` 1; `Pages/Member/Chat.tsx` 1 |
| Arbitrary font sizes `text-[Npx]` | 14 | `Pages/Landing/Index.tsx` 5; `Components/layout/Topbar.tsx` 4; `Pages/ServiceRequests/Board.tsx` 3; `Pages/Member/MemberLayout.tsx` 2 |
| Arbitrary spacing and size values `w-[..]`, `p-[..]`, `top-[..]` | 105 | `Components/layout/Topbar.tsx` 18; `Pages/Property/FastConfigure.tsx` 15; `Pages/HR/EmployeeProfileSections.tsx` 6; `Pages/Channels/Index.tsx` 5; `Pages/Member/MemberLayout.tsx` 5; `Components/layout/BottomNav.tsx` 4; `Pages/HR/EmployeeProfile.tsx` 4; `Components/layout/SyncIndicator.tsx` 3; `Layouts/PublicErrorLayout.tsx` 3; `Pages/Member/Chat.tsx` 3 |
| Arbitrary radius `rounded-[..]` | 0 | none |
| Numeric z-index `z-NN`, `z-[NN]` | 13 | `Pages/Reservations/TapeChart.tsx` 4; `Pages/Member/MemberLayout.tsx` 3; `Components/voice/VoiceNotifier.tsx` 1; `Pages/Finance/Receivables.tsx` 1; `Pages/Landing/Index.tsx` 1; `Pages/Member/ConfirmSheet.tsx` 1; `Pages/Property/FastConfigure.tsx` 1; `Pages/Reservations/Create.tsx` 1 |
| Inline `zIndex:` | 0 | none |
| Off-scale spacing utilities (5, 7, 9, 10, 0.5, 1.5, 2.5) | 268 | by step: 5 114, 0.5 61, 1.5 37, 10 23, 9 16, 2.5 15, 7 2 |
| Shadow / ring / blur utilities | 1 | `Pages/Billing/InvoiceShow.tsx` 1 |

### Tailwind palette use by hue
slate 1608, red 308, emerald 158, amber 136, navy 124, blue 35, brand 28, yellow 19, sky 7, green 7, indigo 5, orange 3, violet 2

### z-index utilities in use
10 4, 20 3, 30 3, [40 3, 50 2, [30 2, [70 2, 40 1

### Radius utilities in use
lg 124, undefined 71, full 24, md 23, xl 3, 2xl 3, sm 1
