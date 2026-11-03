# UI standard (UX1)

Contract: `docs/PLAN-UI-AUDIT.md`. Tokens and component classes live in `resources/css/app.css`; shared React pieces in `resources/js/Components/ui` and `resources/js/Components/layout`. Flat UI: no shadow, ring, blur, gradient, text-shadow or pulse anywhere (the guard test fails the build; `box-shadow: none !important` is also applied globally).

## Tokens
Semantic colours (CSS variables on `:root`, mirrored by Tailwind utilities): `--c-text-primary/secondary/muted`, `--c-surface`, `--c-surface-subtle`, `--c-surface-page`, `--c-border`, `--c-border-control`, `--c-accent` (gold, fill only) with `--c-on-accent` (navy), `--c-accent-text` (gold as text on light), `--c-primary` / `--c-on-primary`, `--c-success|warning|danger|info` with `-subtle` and `--c-on-*` variants, `--c-chrome*` for navy bars. Every pair is declared as `ui-pair:` in app.css and checked by `php scripts/ui-audit/contrast.php` (and `UiStandardTest`). Muted text is `text-slate-500` (token darkened to #526173, 6.3:1 on white); never use `text-slate-400` on light surfaces; gold is never text on white (use `text-brand-800`). Type scale: 13 px minimum (`text-xs`), 15 px `text-sm`, 16 px `text-base`; z-index tokens `--z-sticky 20, --z-bar 30, --z-dropdown 40, --z-sheet 50, --z-modal 60, --z-toast 70`.

## Classes (no per-page work needed)
`.btn-primary` (navy, white text) `.btn-secondary` (outline) `.btn-danger` `.btn-ghost` `.btn-accent` (gold, navy text), sizes `.btn-sm` `.btn-lg` `.btn-icon`, `.btn-row` (wrapping button row). 44 px minimum height and width under 1024 px or on coarse pointers, 40 px (36 for sm) on desktop. `.input .select .textarea .label .field-hint .field-error`. `.card .stat-tile .badge-{green,yellow,red,blue,slate,navy,gold}`. Tabs: `.tabs` + `.tab` (`aria-selected`). Tables: `.table-wrap` (scrolls inside itself, add `.sticky-first` for a sticky first column), `.table-auto`/`.table-std`, and `.table-cards` + `data-label` on each `td` for the card list under 768 px. `.nav-item`, `.nav-item-light`, `.skip-link`, `.scroll-pane`, `.above-tabbar` (lift fixed floaters above the phone tab bar).

## Components (`@/Components/ui`)
```tsx
<Button variant="primary|secondary|danger|ghost|accent" size="sm|md|lg" icon={Plus} loading={busy} href="/x" iconOnly>Label</Button>
<Field label="Email" hint="We never share it" error={errors.email} required><input className="input" type="email" /></Field>   // id, aria-describedby, aria-invalid injected
<FilterBar activeCount={n} onClear={reset} search={<input className="input" aria-label="Search" />}><Field label="Status"><select className="select">…</select></Field></FilterBar> // sheet under 768 px; unlabeled controls get aria-label from placeholder
<DataTable caption="Reservations" columns={[{key:'n',header:'No.'},{key:'t',header:'Total',align:'right',cell:r=>fmt(r.t)}]} rows={rows} rowKey={r=>r.id} stickyFirst loading={busy} />
<Tabs label="Sections" tabs={[{key:'a',label:'A'}]} value={tab} onChange={setTab} />   // navy/gold, scrolls on phones, arrow keys; panel: role="tabpanel" id="panel-a"
<Modal open={o} onClose={close} title="Edit" footer={<Button>Save</Button>}>…</Modal>   // bottom sheet on phones; <Sheet> is always a bottom sheet
toast.success('Saved')  // <ToastRegion/> is mounted by AppLayout (aria-live)
<EmptyState title="…" description="…" action={<Button>New</Button>} />
<PageHeader title="…" subtitle="…" actions={…} heading={false} />   // heading={false} inside AppLayout, which already renders the h1
<Skeleton className="h-24" /> / <SkeletonRows />   // static, no pulse
```
Hooks: `useIsPhone()`, `useIsTablet()`, `useMediaQuery()` in `@/lib/useMediaQuery`. Tab bar destinations come from `props.nav` (`pickTabs` in `Components/layout/navModel.ts`).

## Shell
`AppLayout`: phone (<768) top app bar + bottom tab bar (4 destinations + More sheet with the full grouped menu, language and hotel), tablet icon rail, desktop collapsible sidebar (state remembered). Safe-area insets (viewport-fit=cover), 100dvh, only `main` scrolls, skip link, `h1#page-title` receives focus after every Inertia navigation, `aria-current="page"` on the active item (longest matching path wins). Pages should pass `title`, `subtitle`, `actions` to `AppLayout` and must not add a second h1. Page actions show inline from 1536 px and on a second row below that. Errors for signed-out users: `Layouts/PublicErrorLayout` (no session needed, links home).

## Running the audit harness
1. Start the app (`php artisan serve --port=8020`; use `localhost:8020` if another agent is using `127.0.0.1:8000`, cookies are per host so sessions do not clash). Sign in via the one-click demo login (`/login`, choose General Manager).
2. Copy the harness where the page can load it: `cp scripts/ui-audit/audit.js public/ui-audit.js` (gitignored; delete when done). The page CSP blocks `eval` and `new Function`, so it is loaded with a script tag, not evaluated from a string.
3. Per page and width: `resize_window` (375x812, 768x1024, 1280x800), `navigate`, then `javascript_exec`:
```js
const sl=ms=>new Promise(r=>setTimeout(r,ms)); for(let i=0;i<40&&!document.querySelector('header h1');i++) await sl(300); await sl(1500);
for(let t=0;t<3&&typeof __uiAudit!=='function';t++){ await Promise.race([new Promise(r=>{const s=document.createElement('script');s.src='/ui-audit.js?'+Date.now();s.onload=r;s.onerror=r;document.head.append(s)}), sl(8000)]) }
JSON.stringify(__uiAudit())
```
Wait for the real page before auditing (a half-loaded page reports a missing h1). Routes to cover: `scripts/ui-audit/routes.json` (rebuild with `build-routes.cjs`). Reset with `resize_window preset desktop` at the end. Rules and critical set are described at the top of `audit.js`.
4. Shared browser pane: audit runs must be serial. One agent at a time; open your own tab (`tabs_create`), pass its `tabId` on every call, never use the fronted tab, and close it (`tabs_close`) when finished. Other agents navigating or signing out in a shared session invalidates results.
5. Contrast tokens: `php scripts/ui-audit/contrast.php`. Guard: `php artisan test tests/Feature/Platform/UiStandardTest.php` (file scans, no database).
