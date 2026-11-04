import AppLayout from '@/Layouts/AppLayout';
import { Head } from '@inertiajs/react';
import { ChevronDown, Download, Plus, RotateCcw, Trash2, Upload } from 'lucide-react';
import { useEffect, useMemo, useRef, useState, type ReactNode } from 'react';
import Button from '@/Components/ui/Button';
import QueryStatus from '@/Components/ui/QueryStatus';
import { EmptyFor } from '@/Components/ui/EmptyStates';
import { api, ApiError, useApi } from '@/lib/api';
import { useT } from '@/lib/i18n';
import { cn, formatCurrency } from '@/lib/utils';
import Alert from '@/Components/ui/Alert';

interface Bed { type: string; count: number }
interface TypeCfg {
    code: string; name: string; share_mode: 'percent' | 'count'; share: number; max_adults: number; max_children: number; beds: Bed[];
    size: number | null; view: string; smoking: boolean; amenities: string[]; amenities_extra: string; base_price: number; extra_adult: number; extra_child: number;
    weekend_uplift: number; high_season_uplift: number; assign: string;
}
interface PlanCfg { code: string; name: string; enabled: boolean; percent: number; meal_plan: string; refundable?: boolean; members_only?: boolean; public?: boolean }
interface Cfg {
    hotel: { id?: number | null; create?: { name: string; city: string; stars: number } };
    floors: { mode: 'numeric' | 'ground' | 'custom'; count: number; custom: string[]; default_rooms: number; per_floor: Record<string, number> };
    numbering: { pattern: string; prefix: string; pad: number; start: number; skip_13: boolean; skip: string[] };
    types: TypeCfg[]; manual_grid: Record<string, string>; explicit_rooms?: { number: string; floor: string; type: string }[]; rate_plans: PlanCfg[];
    outlets: { enabled: boolean; items: string[] }; options: { update_existing: boolean; remove_missing: boolean };
}
interface Room { number: string; floor: string; type: string; beds: number; price: number; weekend_price: number }
interface Warning { code: string; params: Record<string, string | number> }
interface Section { created: string[]; updated: string[]; skipped: { code: string; reason: string }[]; blocked: { code: string; reason: string }[]; removed: string[] }
interface Report {
    hotel: { id: number; code: string; name: string; created: boolean }; dry_run: boolean; room_types: Section; rooms: Section; rate_plans: Section; outlets: Section; stores: Section;
    blocked: { kind: string; code: string; reason: string }[]; new_hotel?: boolean;
}
interface Preview {
    floors: { label: string; rooms: Room[] }[]; types: (TypeCfg & { rooms: number; beds_total: number })[]; warnings: Warning[]; diff: Report | { new_hotel: true } | null;
    totals: { rooms: number; floors: number; beds: number; capacity: number; revenue_100: number; revenue_60: number; avg_rate: number; weekend_revenue_100: number };
}
interface Catalog { presets: Record<string, { label: string; rooms: number }>; bed_types: string[]; amenities: string[]; outlet_items: string[] }
interface Hotel { id: number; name: string; code: string }

const VIEWS = ['', 'sea', 'garden', 'city', 'pool', 'mountain'];
const ASSIGN = ['spread', 'top', 'ground', 'block', 'manual'];

const EMPTY: Cfg = {
    hotel: { id: null, create: { name: '', city: '', stars: 3 } },
    floors: { mode: 'numeric', count: 3, custom: [], default_rooms: 10, per_floor: {} },
    numbering: { pattern: 'floor100', prefix: '', pad: 0, start: 1, skip_13: false, skip: [] },
    types: [], manual_grid: {}, rate_plans: [
        { code: 'BAR', name: 'Meilleur tarif disponible', enabled: true, percent: 0, meal_plan: 'RO' },
        { code: 'BB', name: 'Chambre + petit-déjeuner', enabled: true, percent: 12, meal_plan: 'BB' },
        { code: 'NRF', name: 'Non remboursable', enabled: true, percent: -10, meal_plan: 'RO', refundable: false },
        { code: 'MEM', name: 'Tarif membres', enabled: true, percent: -15, meal_plan: 'RO', members_only: true },
        { code: 'CORP', name: 'Tarif entreprise', enabled: true, percent: -20, meal_plan: 'RO', public: false },
    ],
    outlets: { enabled: false, items: [] }, options: { update_existing: false, remove_missing: false },
};

const newType = (n: number): TypeCfg => ({
    code: `T${n}`, name: '', share_mode: 'percent', share: 0, max_adults: 2, max_children: 0, beds: [{ type: 'double', count: 1 }], size: null, view: '', smoking: false,
    amenities: ['wifi'], amenities_extra: '', base_price: 30000, extra_adult: 5000, extra_child: 2500, weekend_uplift: 0, high_season_uplift: 0, assign: 'spread',
});

const num = (v: string) => (v === '' ? 0 : Number(v));
const k = (n: number) => (n >= 1000 ? `${Math.round(n / 100) / 10}k` : String(n));

function Acc({ id, title, summary, open, onToggle, children }: { id: string; title: string; summary?: string; open: boolean; onToggle: () => void; children: ReactNode }) {
    return (
        <section className="card">
            <h2>
                <button type="button" id={`${id}-h`} aria-expanded={open} aria-controls={`${id}-p`} onClick={onToggle}
                    className="flex w-full min-h-[44px] items-center justify-between gap-3 px-4 py-2 text-left">
                    <span className="min-w-0">
                        <span className="block text-base font-semibold text-slate-900">{title}</span>
                        {summary && <span className="block truncate text-sm text-slate-600">{summary}</span>}
                    </span>
                    <ChevronDown className={cn('h-5 w-5 shrink-0 text-slate-600 transition-transform', open && 'rotate-180')} aria-hidden="true" />
                </button>
            </h2>
            <div id={`${id}-p`} role="region" aria-labelledby={`${id}-h`} hidden={!open} className="border-t border-slate-200 p-4">{children}</div>
        </section>
    );
}

function Num({ label, value, onChange, min = 0, step = 1, suffix }: { label: string; value: number | null; onChange: (v: number) => void; min?: number; step?: number; suffix?: string }) {
    return (
        <label className="block">
            <span className="label">{label}{suffix ? ` (${suffix})` : ''}</span>
            <input className="input" type="number" inputMode="decimal" min={min} step={step} value={value ?? ''} onChange={(e) => onChange(num(e.target.value))} />
        </label>
    );
}

export default function FastConfigure() {
    const t = useT();
    const [cfg, setCfg] = useState<Cfg>(EMPTY);
    const [open, setOpen] = useState<Record<string, boolean>>({ hotel: true, floors: true, types: true });
    const [preview, setPreview] = useState<Preview | null>(null);
    const [busy, setBusy] = useState<string | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [report, setReport] = useState<Report | null>(null);
    const [confirm, setConfirm] = useState(false);
    const [editGrid, setEditGrid] = useState(false);
    const [target, setTarget] = useState<'new' | 'existing'>('new');
    const [presetKey, setPresetKey] = useState<string | null>(null);
    const [importText, setImportText] = useState<string | null>(null);
    const catalog = useApi<Catalog>('property/fast-configure/presets');
    const hotels = useApi<{ data: Hotel[] }>('hotels');
    const seq = useRef(0);

    const toggle = (id: string) => setOpen((o) => ({ ...o, [id]: !o[id] }));
    const set = <K extends keyof Cfg>(key: K, v: Cfg[K]) => setCfg((c) => ({ ...c, [key]: v }));
    const setType = (i: number, patch: Partial<TypeCfg>) => setCfg((c) => ({ ...c, types: c.types.map((x, j) => (j === i ? { ...x, ...patch } : x)) }));
    const setPlan = (i: number, patch: Partial<PlanCfg>) => setCfg((c) => ({ ...c, rate_plans: c.rate_plans.map((x, j) => (j === i ? { ...x, ...patch } : x)) }));

    const payload = useMemo(() => ({ ...cfg, hotel: target === 'existing' ? { id: cfg.hotel.id } : { create: cfg.hotel.create } }), [cfg, target]);

    // Live preview, debounced; the newest answer wins.
    useEffect(() => {
        if (!cfg.types.length) { setPreview(null); return; }
        const my = ++seq.current;
        const h = setTimeout(() => {
            api<Preview>('property/fast-configure/preview', { method: 'POST', body: JSON.stringify({ config: payload }) })
                .then((p) => { if (my === seq.current) { setPreview(p); setError(null); } })
                .catch((e) => { if (my === seq.current) setError(e instanceof ApiError ? e.message : String(e)); });
        }, 400);
        return () => clearTimeout(h);
    }, [payload, cfg.types.length]);

    async function loadPreset(key: string) {
        const p = await api<Cfg & { preset_label?: string }>(`property/fast-configure/presets/${key}`);
        setCfg({ ...EMPTY, ...p, manual_grid: {}, numbering: { ...EMPTY.numbering, ...p.numbering }, floors: { ...EMPTY.floors, ...p.floors } });
        setTarget('new');
        setPresetKey(key);
        setReport(null);
        setConfirm(false);
    }
    const reset = () => { setCfg(EMPTY); setReport(null); setConfirm(false); setError(null); };

    async function run(dry: boolean) {
        setBusy(dry ? 'dry' : 'apply');
        setError(null);
        try {
            const r = await api<Report>('property/fast-configure/apply', { method: 'POST', body: JSON.stringify({ config: payload, dry_run: dry }) });
            setReport(r);
            setConfirm(false);
            if (!dry) hotels.reload();
        } catch (e) {
            setError(e instanceof ApiError ? e.message : String(e));
        } finally { setBusy(null); }
    }

    async function demo() {
        const preset = presetKey ?? Object.keys(catalog.data?.presets ?? {})[0];
        if (!preset) return;
        setBusy('demo');
        setError(null);
        try {
            setReport(await api<Report>('property/fast-configure/demo', { method: 'POST', body: JSON.stringify({ preset }) }));
            hotels.reload();
        } catch (e) { setError(e instanceof ApiError ? e.message : String(e)); } finally { setBusy(null); }
    }

    async function exportHotel() {
        const id = target === 'existing' && cfg.hotel.id ? cfg.hotel.id : null;
        const data = id ? await api<Cfg>(`property/fast-configure/export?hotel_id=${id}`) : payload;
        const url = URL.createObjectURL(new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' }));
        const a = document.createElement('a');
        a.href = url; a.download = 'fast-configure.json'; a.click();
        URL.revokeObjectURL(url);
    }

    function importJson() {
        try {
            const p = JSON.parse(importText ?? '');
            setCfg({ ...EMPTY, ...p, numbering: { ...EMPTY.numbering, ...p.numbering }, floors: { ...EMPTY.floors, ...p.floors }, manual_grid: p.manual_grid ?? {} });
            setTarget('new');
            setImportText(null);
            setError(null);
        } catch { setError(t('fastconfig.import.invalid')); }
    }

    const warn = (w: Warning) => t(`fastconfig.warn.${w.code}`, w.params as Record<string, string | number>);
    const typeCodes = cfg.types.map((x) => x.code).filter(Boolean);
    const tot = preview?.totals;
    const diffReport = preview?.diff && 'rooms' in preview.diff ? preview.diff : null;
    const canApply = !!tot && tot.rooms > 0 && (target === 'new' ? !!cfg.hotel.create?.name.trim() : !!cfg.hotel.id);

    return (
        <AppLayout title={t('fastconfig.title')} subtitle={t('fastconfig.subtitle')}>
            <Head title={t('fastconfig.title')} />
            <QueryStatus query={catalog} variant="list" rows={2} />

            <div className="card mb-4 p-4">
                <p className="label">{t('fastconfig.presets')}</p>
                <div className="flex flex-wrap gap-2">
                    {Object.entries(catalog.data?.presets ?? {}).map(([key, p]) => (
                        <Button key={key} variant="secondary" onClick={() => loadPreset(key)}>{p.label}</Button>
                    ))}
                    <Button variant="ghost" icon={RotateCcw} onClick={reset}>{t('fastconfig.reset')}</Button>
                    <Button variant="ghost" icon={Download} onClick={exportHotel}>{t('fastconfig.export')}</Button>
                    <Button variant="ghost" icon={Upload} onClick={() => setImportText('')}>{t('fastconfig.import')}</Button>
                    <Button variant="accent" loading={busy === 'demo'} onClick={demo}>{t('fastconfig.demo')}</Button>
                </div>
                {importText !== null && (
                    <div className="mt-3 space-y-2">
                        <label className="block"><span className="label">{t('fastconfig.import.paste')}</span>
                            <textarea className="textarea font-mono text-sm" rows={6} value={importText} onChange={(e) => setImportText(e.target.value)} /></label>
                        <input type="file" accept="application/json,.json" aria-label={t('fastconfig.import.file')} className="input" onChange={async (e) => { const f = e.target.files?.[0]; if (f) setImportText(await f.text()); }} />
                        <div className="flex gap-2"><Button onClick={importJson}>{t('fastconfig.import.load')}</Button><Button variant="ghost" onClick={() => setImportText(null)}>{t('fastconfig.cancel')}</Button></div>
                    </div>
                )}
            </div>

            {error && <Alert variant="danger" className="mb-4">{error}</Alert>}

            <div className="grid gap-4 pb-24 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.1fr)] lg:pb-0 [&>*]:min-w-0">
                {/* ---------------------------------------------------------------- form */}
                <div className="space-y-3">
                    <Acc id="hotel" title={t('fastconfig.step.hotel')} summary={target === 'new' ? cfg.hotel.create?.name || t('fastconfig.hotel.new') : hotels.data?.data.find((h) => h.id === cfg.hotel.id)?.name} open={!!open.hotel} onToggle={() => toggle('hotel')}>
                        <fieldset className="mb-3 flex flex-wrap gap-4">
                            <legend className="label">{t('fastconfig.hotel.target')}</legend>
                            {(['new', 'existing'] as const).map((m) => (
                                <label key={m} className="flex min-h-[44px] items-center gap-2 text-sm"><input type="radio" name="fc-target" className="h-5 w-5" checked={target === m} onChange={() => setTarget(m)} />{t(`fastconfig.hotel.${m}`)}</label>
                            ))}
                        </fieldset>
                        {target === 'existing' ? (
                            <label className="block"><span className="label">{t('fastconfig.hotel.pick')}</span>
                                <select className="select" value={cfg.hotel.id ?? ''} onChange={(e) => setCfg((c) => ({ ...c, hotel: { ...c.hotel, id: e.target.value ? Number(e.target.value) : null } }))}>
                                    <option value="">{t('fastconfig.hotel.choose')}</option>
                                    {hotels.data?.data.map((h) => <option key={h.id} value={h.id}>{h.name} ({h.code})</option>)}
                                </select></label>
                        ) : (
                            <div className="grid gap-3 sm:grid-cols-3 [&>*]:min-w-0">
                                <label className="block sm:col-span-2"><span className="label">{t('fastconfig.hotel.name')}</span>
                                    <input className="input" value={cfg.hotel.create?.name ?? ''} onChange={(e) => setCfg((c) => ({ ...c, hotel: { create: { ...c.hotel.create!, name: e.target.value } } }))} /></label>
                                <label className="block"><span className="label">{t('fastconfig.hotel.stars')}</span>
                                    <select className="select" value={cfg.hotel.create?.stars ?? 3} onChange={(e) => setCfg((c) => ({ ...c, hotel: { create: { ...c.hotel.create!, stars: Number(e.target.value) } } }))}>
                                        {[1, 2, 3, 4, 5].map((s) => <option key={s} value={s}>{s}</option>)}</select></label>
                                <label className="block sm:col-span-3"><span className="label">{t('fastconfig.hotel.city')}</span>
                                    <input className="input" value={cfg.hotel.create?.city ?? ''} onChange={(e) => setCfg((c) => ({ ...c, hotel: { create: { ...c.hotel.create!, city: e.target.value } } }))} /></label>
                            </div>
                        )}
                    </Acc>

                    <Acc id="floors" title={t('fastconfig.step.floors')} summary={t('fastconfig.floors.summary', { floors: preview?.totals.floors ?? 0, rooms: tot?.rooms ?? 0 })} open={!!open.floors} onToggle={() => toggle('floors')}>
                        <div className="grid gap-3 sm:grid-cols-3 [&>*]:min-w-0">
                            <label className="block"><span className="label">{t('fastconfig.floors.mode')}</span>
                                <select className="select" value={cfg.floors.mode} onChange={(e) => set('floors', { ...cfg.floors, mode: e.target.value as Cfg['floors']['mode'] })}>
                                    {['numeric', 'ground', 'custom'].map((m) => <option key={m} value={m}>{t(`fastconfig.floors.mode.${m}`)}</option>)}</select></label>
                            {cfg.floors.mode !== 'custom' && <Num label={t('fastconfig.floors.count')} value={cfg.floors.count} min={1} onChange={(v) => set('floors', { ...cfg.floors, count: v })} />}
                            <Num label={t('fastconfig.floors.default')} value={cfg.floors.default_rooms} onChange={(v) => set('floors', { ...cfg.floors, default_rooms: v })} />
                        </div>
                        {cfg.floors.mode === 'custom' && (
                            <label className="mt-3 block"><span className="label">{t('fastconfig.floors.custom')}</span>
                                <input className="input" value={cfg.floors.custom.join(', ')} onChange={(e) => set('floors', { ...cfg.floors, custom: e.target.value.split(',').map((x) => x.trim()).filter(Boolean) })} /></label>
                        )}
                        <p className="label mt-4">{t('fastconfig.floors.overrides')}</p>
                        <div className="grid grid-cols-2 gap-2 sm:grid-cols-4 [&>*]:min-w-0">
                            {(preview?.floors.map((f) => f.label) ?? (cfg.floors.mode === 'custom' ? cfg.floors.custom : [])).map((label) => (
                                <label key={label} className="block"><span className="text-xs text-slate-600">{t('fastconfig.floors.floor', { label })}</span>
                                    <input className="input" type="number" min={0} inputMode="numeric" placeholder={String(cfg.floors.default_rooms)} value={cfg.floors.per_floor[label] ?? ''}
                                        onChange={(e) => { const pf = { ...cfg.floors.per_floor }; if (e.target.value === '') delete pf[label]; else pf[label] = num(e.target.value); set('floors', { ...cfg.floors, per_floor: pf }); }} /></label>
                            ))}
                        </div>
                    </Acc>

                    <Acc id="numbering" title={t('fastconfig.step.numbering')} summary={t(`fastconfig.numbering.${cfg.numbering.pattern}`)} open={!!open.numbering} onToggle={() => toggle('numbering')}>
                        <div className="grid gap-3 sm:grid-cols-3 [&>*]:min-w-0">
                            <label className="block sm:col-span-3"><span className="label">{t('fastconfig.numbering.pattern')}</span>
                                <select className="select" value={cfg.numbering.pattern} onChange={(e) => set('numbering', { ...cfg.numbering, pattern: e.target.value })}>
                                    {['floor100', 'prefix', 'letter', 'sequential'].map((p) => <option key={p} value={p}>{t(`fastconfig.numbering.${p}`)}</option>)}</select></label>
                            {cfg.numbering.pattern === 'prefix' && <label className="block"><span className="label">{t('fastconfig.numbering.prefix')}</span><input className="input" maxLength={4} value={cfg.numbering.prefix} onChange={(e) => set('numbering', { ...cfg.numbering, prefix: e.target.value })} /></label>}
                            {cfg.numbering.pattern === 'sequential' && <Num label={t('fastconfig.numbering.start')} value={cfg.numbering.start} min={1} onChange={(v) => set('numbering', { ...cfg.numbering, start: v })} />}
                            <Num label={t('fastconfig.numbering.pad')} value={cfg.numbering.pad} onChange={(v) => set('numbering', { ...cfg.numbering, pad: v })} />
                            <label className="block sm:col-span-2"><span className="label">{t('fastconfig.numbering.skip')}</span>
                                <input className="input" placeholder="13, 113" value={cfg.numbering.skip.join(', ')} onChange={(e) => set('numbering', { ...cfg.numbering, skip: e.target.value.split(',').map((x) => x.trim()).filter(Boolean) })} /></label>
                        </div>
                        <label className="mt-3 flex min-h-[44px] items-center gap-2 text-sm"><input type="checkbox" className="h-5 w-5" checked={cfg.numbering.skip_13} onChange={(e) => set('numbering', { ...cfg.numbering, skip_13: e.target.checked })} />{t('fastconfig.numbering.skip13')}</label>
                    </Acc>

                    <Acc id="types" title={t('fastconfig.step.types')} summary={t('fastconfig.types.summary', { count: cfg.types.length })} open={!!open.types} onToggle={() => toggle('types')}>
                        {cfg.types.length === 0 && <EmptyFor kind="rooms" action={<Button variant="secondary" icon={Plus} onClick={() => setCfg((c) => ({ ...c, types: [...c.types, newType(c.types.length + 1)] }))}>{t('fastconfig.types.add')}</Button>} />}
                        <div className="space-y-4">
                            {cfg.types.map((ty, i) => {
                                const beds = ty.beds.reduce((a, b) => a + b.count, 0);
                                return (
                                    <div key={i} className="rounded-lg border border-slate-200 p-3">
                                        <div className="mb-3 flex items-center justify-between gap-2">
                                            <p className="font-semibold text-slate-900">{ty.code || '—'} <span className="font-normal text-slate-600">{ty.name}</span></p>
                                            <Button variant="ghost" size="sm" icon={Trash2} iconOnly onClick={() => setCfg((c) => ({ ...c, types: c.types.filter((_, j) => j !== i) }))}>{t('fastconfig.types.remove')}</Button>
                                        </div>
                                        <div className="grid gap-3 sm:grid-cols-4 [&>*]:min-w-0">
                                            <label className="block"><span className="label">{t('fastconfig.type.code')}</span><input className="input" maxLength={12} value={ty.code} onChange={(e) => setType(i, { code: e.target.value.toUpperCase() })} /></label>
                                            <label className="block sm:col-span-3"><span className="label">{t('fastconfig.type.name')}</span><input className="input" value={ty.name} onChange={(e) => setType(i, { name: e.target.value })} /></label>
                                            <label className="block"><span className="label">{t('fastconfig.type.share_mode')}</span>
                                                <select className="select" value={ty.share_mode} onChange={(e) => setType(i, { share_mode: e.target.value as 'percent' | 'count' })}>
                                                    <option value="percent">{t('fastconfig.type.percent')}</option><option value="count">{t('fastconfig.type.count')}</option></select></label>
                                            <Num label={ty.share_mode === 'percent' ? t('fastconfig.type.share_percent') : t('fastconfig.type.share_count')} value={ty.share} onChange={(v) => setType(i, { share: v })} />
                                            <Num label={t('fastconfig.type.adults')} value={ty.max_adults} min={1} onChange={(v) => setType(i, { max_adults: v })} />
                                            <Num label={t('fastconfig.type.children')} value={ty.max_children} onChange={(v) => setType(i, { max_children: v })} />
                                            <Num label={t('fastconfig.type.size')} value={ty.size} suffix="m²" onChange={(v) => setType(i, { size: v || null })} />
                                            <label className="block"><span className="label">{t('fastconfig.type.view')}</span>
                                                <select className="select" value={ty.view} onChange={(e) => setType(i, { view: e.target.value })}>
                                                    {VIEWS.map((v) => <option key={v} value={v}>{t(`fastconfig.view.${v || 'none'}`)}</option>)}</select></label>
                                            <label className="block"><span className="label">{t('fastconfig.type.assign')}</span>
                                                <select className="select" value={ty.assign} onChange={(e) => setType(i, { assign: e.target.value })}>
                                                    {ASSIGN.map((a) => <option key={a} value={a}>{t(`fastconfig.assign.${a}`)}</option>)}</select></label>
                                            <label className="flex min-h-[44px] items-center gap-2 self-end text-sm"><input type="checkbox" className="h-5 w-5" checked={ty.smoking} onChange={(e) => setType(i, { smoking: e.target.checked })} />{t('fastconfig.type.smoking')}</label>
                                        </div>

                                        <p className="label mt-4">{t('fastconfig.type.beds', { count: beds })}</p>
                                        <div className="space-y-2">
                                            {ty.beds.map((b, bi) => (
                                                <div key={bi} className="flex gap-2">
                                                    <select className="select flex-1" aria-label={t('fastconfig.type.bed_type')} value={b.type} onChange={(e) => setType(i, { beds: ty.beds.map((x, j) => (j === bi ? { ...x, type: e.target.value } : x)) })}>
                                                        {catalog.data?.bed_types.map((bt) => <option key={bt} value={bt}>{t(`fastconfig.bed.${bt}`)}</option>)}</select>
                                                    <input className="input w-20" type="number" min={1} max={20} inputMode="numeric" aria-label={t('fastconfig.type.bed_count')} value={b.count} onChange={(e) => setType(i, { beds: ty.beds.map((x, j) => (j === bi ? { ...x, count: num(e.target.value) } : x)) })} />
                                                    <Button variant="ghost" iconOnly icon={Trash2} onClick={() => setType(i, { beds: ty.beds.filter((_, j) => j !== bi) })}>{t('fastconfig.type.remove_bed')}</Button>
                                                </div>
                                            ))}
                                            <Button variant="secondary" size="sm" icon={Plus} onClick={() => setType(i, { beds: [...ty.beds, { type: 'single', count: 1 }] })}>{t('fastconfig.type.add_bed')}</Button>
                                        </div>

                                        <p className="label mt-4">{t('fastconfig.type.amenities')}</p>
                                        <div className="flex flex-wrap gap-2">
                                            {catalog.data?.amenities.map((a) => {
                                                const on = ty.amenities.includes(a);
                                                return <button key={a} type="button" aria-pressed={on} onClick={() => setType(i, { amenities: on ? ty.amenities.filter((x) => x !== a) : [...ty.amenities, a] })}
                                                    className={cn('min-h-[44px] rounded-full border px-3 text-sm font-medium', on ? 'border-navy-900 bg-navy-900 text-white' : 'border-slate-400 bg-white text-slate-800')}>{t(`fastconfig.amenity.${a}`)}</button>;
                                            })}
                                        </div>
                                        <label className="mt-2 block"><span className="label">{t('fastconfig.type.amenities_extra')}</span>
                                            <input className="input" value={ty.amenities_extra} onChange={(e) => setType(i, { amenities_extra: e.target.value })} /></label>

                                        <div className="mt-4 grid gap-3 sm:grid-cols-3 [&>*]:min-w-0">
                                            <Num label={t('fastconfig.type.base_price')} suffix="XAF" step={500} value={ty.base_price} onChange={(v) => setType(i, { base_price: v })} />
                                            <Num label={t('fastconfig.type.extra_adult')} suffix="XAF" step={500} value={ty.extra_adult} onChange={(v) => setType(i, { extra_adult: v })} />
                                            <Num label={t('fastconfig.type.extra_child')} suffix="XAF" step={500} value={ty.extra_child} onChange={(v) => setType(i, { extra_child: v })} />
                                            <Num label={t('fastconfig.type.weekend')} suffix="%" value={ty.weekend_uplift} onChange={(v) => setType(i, { weekend_uplift: v })} />
                                            <Num label={t('fastconfig.type.high_season')} suffix="%" value={ty.high_season_uplift} onChange={(v) => setType(i, { high_season_uplift: v })} />
                                        </div>
                                    </div>
                                );
                            })}
                            <Button variant="secondary" icon={Plus} onClick={() => setCfg((c) => ({ ...c, types: [...c.types, newType(c.types.length + 1)] }))}>{t('fastconfig.types.add')}</Button>
                        </div>
                    </Acc>

                    <Acc id="plans" title={t('fastconfig.step.plans')} summary={t('fastconfig.plans.summary', { count: cfg.rate_plans.filter((p) => p.enabled).length })} open={!!open.plans} onToggle={() => toggle('plans')}>
                        <div className="space-y-3">
                            {cfg.rate_plans.map((p, i) => (
                                <div key={p.code} className="grid items-end gap-2 sm:grid-cols-[auto_1fr_8rem] [&>*]:min-w-0">
                                    <label className="flex min-h-[44px] items-center gap-2 text-sm font-semibold"><input type="checkbox" className="h-5 w-5" checked={p.enabled} disabled={p.code === 'BAR'} onChange={(e) => setPlan(i, { enabled: e.target.checked })} />{p.code}</label>
                                    <label className="block"><span className="label">{t('fastconfig.plan.name')}</span><input className="input" value={p.name} onChange={(e) => setPlan(i, { name: e.target.value })} /></label>
                                    {p.code === 'BAR' ? <p className="pb-3 text-sm text-slate-600">{t('fastconfig.plan.root')}</p> : <Num label={t('fastconfig.plan.percent')} suffix="%" min={-90} value={p.percent} onChange={(v) => setPlan(i, { percent: v })} />}
                                </div>
                            ))}
                        </div>
                    </Acc>

                    <Acc id="outlets" title={t('fastconfig.step.outlets')} summary={cfg.outlets.enabled ? t('fastconfig.outlets.summary', { count: cfg.outlets.items.length }) : t('fastconfig.outlets.off')} open={!!open.outlets} onToggle={() => toggle('outlets')}>
                        <label className="flex min-h-[44px] items-center gap-2 text-sm font-semibold"><input type="checkbox" className="h-5 w-5" checked={cfg.outlets.enabled} onChange={(e) => set('outlets', { ...cfg.outlets, enabled: e.target.checked })} />{t('fastconfig.outlets.enable')}</label>
                        {cfg.outlets.enabled && (
                            <div className="mt-2 flex flex-wrap gap-2">
                                {catalog.data?.outlet_items.map((it) => {
                                    const on = cfg.outlets.items.includes(it);
                                    return <button key={it} type="button" aria-pressed={on} onClick={() => set('outlets', { ...cfg.outlets, items: on ? cfg.outlets.items.filter((x) => x !== it) : [...cfg.outlets.items, it] })}
                                        className={cn('min-h-[44px] rounded-full border px-3 text-sm font-medium', on ? 'border-navy-900 bg-navy-900 text-white' : 'border-slate-400 bg-white text-slate-800')}>{t(`fastconfig.outlet.${it}`)}</button>;
                                })}
                            </div>
                        )}
                    </Acc>

                    <Acc id="options" title={t('fastconfig.step.options')} open={!!open.options} onToggle={() => toggle('options')}>
                        <label className="flex min-h-[44px] items-start gap-2 text-sm"><input type="checkbox" className="mt-2.5 h-5 w-5" checked={cfg.options.update_existing} onChange={(e) => set('options', { ...cfg.options, update_existing: e.target.checked })} /><span className="py-2.5">{t('fastconfig.options.update')}</span></label>
                        <label className="flex min-h-[44px] items-start gap-2 text-sm"><input type="checkbox" className="mt-2.5 h-5 w-5" checked={cfg.options.remove_missing} onChange={(e) => set('options', { ...cfg.options, remove_missing: e.target.checked })} /><span className="py-2.5">{t('fastconfig.options.remove')}</span></label>
                        <p className="text-sm text-slate-600">{t('fastconfig.options.safe')}</p>
                    </Acc>
                </div>

                {/* ---------------------------------------------------------------- preview */}
                <div className="min-w-0 space-y-3 lg:sticky lg:top-4 lg:self-start">
                    <section className="card p-4" aria-labelledby="fc-prev">
                        <h2 id="fc-prev" className="mb-3 text-base font-semibold text-slate-900">{t('fastconfig.preview')}</h2>
                        {!tot && <p className="text-sm text-slate-600">{t('fastconfig.preview.empty')}</p>}
                        {tot && (
                            <>
                                <dl className="grid grid-cols-2 gap-2 sm:grid-cols-3 [&>*]:min-w-0">
                                    {([['rooms', tot.rooms], ['beds', tot.beds], ['capacity', tot.capacity]] as const).map(([key, v]) => (
                                        <div key={key} className="rounded-lg bg-slate-100 p-2"><dt className="text-xs text-slate-600">{t(`fastconfig.total.${key}`)}</dt><dd className="text-lg font-semibold text-slate-900">{v}</dd></div>
                                    ))}
                                    <div className="rounded-lg bg-slate-100 p-2"><dt className="text-xs text-slate-600">{t('fastconfig.total.revenue_100')}</dt><dd className="text-base font-semibold text-slate-900">{formatCurrency(tot.revenue_100)}</dd></div>
                                    <div className="rounded-lg bg-slate-100 p-2"><dt className="text-xs text-slate-600">{t('fastconfig.total.revenue_60')}</dt><dd className="text-base font-semibold text-slate-900">{formatCurrency(tot.revenue_60)}</dd></div>
                                    <div className="rounded-lg bg-slate-100 p-2"><dt className="text-xs text-slate-600">{t('fastconfig.total.avg_rate')}</dt><dd className="text-base font-semibold text-slate-900">{formatCurrency(tot.avg_rate)}</dd></div>
                                </dl>

                                {preview!.warnings.length > 0 && (
                                    <ul className="mt-3 space-y-1" aria-label={t('fastconfig.warnings')}>
                                        {preview!.warnings.slice(0, 12).map((w, i) => <li key={i} className="rounded-md border border-amber-400 bg-amber-50 px-3 py-2 text-sm text-amber-900">{warn(w)}</li>)}
                                    </ul>
                                )}

                                {diffReport && (
                                    <p className="mt-3 text-sm text-slate-700">{t('fastconfig.diff', { rooms: diffReport.rooms.created.length, types: diffReport.room_types.created.length, plans: diffReport.rate_plans.created.length, skipped: diffReport.rooms.skipped.length, blocked: diffReport.blocked.length })}</p>
                                )}
                                {preview!.diff && 'new_hotel' in preview!.diff && !('rooms' in preview!.diff) && <p className="mt-3 text-sm text-slate-700">{t('fastconfig.diff.new_hotel')}</p>}

                                <div className="mt-4 flex items-center justify-between gap-2">
                                    <h3 className="text-sm font-semibold text-slate-900">{t('fastconfig.grid')}</h3>
                                    <label className="flex min-h-[44px] items-center gap-2 text-sm"><input type="checkbox" className="h-5 w-5" checked={editGrid} onChange={(e) => setEditGrid(e.target.checked)} />{t('fastconfig.grid.edit')}</label>
                                </div>
                                <div className="max-h-[60vh] overflow-auto rounded-lg border border-slate-200" tabIndex={0} role="region" aria-label={t('fastconfig.grid')}>
                                    {preview!.floors.slice().reverse().map((f) => (
                                        <div key={f.label} className="flex gap-2 border-b border-slate-200 p-2 last:border-0">
                                            <div className="sticky left-0 w-12 shrink-0 bg-white pt-1 text-sm font-semibold text-slate-800">{t('fastconfig.floors.floor', { label: f.label })}</div>
                                            <div className="flex gap-2">
                                                {f.rooms.map((r) => (
                                                    <div key={r.number} className="w-24 shrink-0 rounded-md border border-slate-300 bg-white p-1.5 text-xs text-slate-700">
                                                        <p className="text-sm font-bold text-slate-900">{r.number}</p>
                                                        {editGrid ? (
                                                            <select className="select min-h-[44px] px-1 text-sm" aria-label={t('fastconfig.grid.type_of', { number: r.number })} value={r.type}
                                                                onChange={(e) => set('manual_grid', { ...cfg.manual_grid, [r.number]: e.target.value })}>
                                                                {typeCodes.map((c) => <option key={c} value={c}>{c}</option>)}</select>
                                                        ) : <p className="font-semibold">{r.type}</p>}
                                                        <p>{t('fastconfig.grid.beds', { count: r.beds })}</p>
                                                        <p>{k(r.price)}</p>
                                                    </div>
                                                ))}
                                            </div>
                                        </div>
                                    ))}
                                </div>
                                <div className="mt-3 table-wrap">
                                    <table className="table-auto w-full text-sm">
                                        <thead><tr className="text-left text-slate-600"><th className="py-1 pr-2">{t('fastconfig.type.code')}</th><th className="pr-2">{t('fastconfig.total.rooms')}</th><th className="pr-2">{t('fastconfig.type.base_price')}</th><th className="pr-2">{t('fastconfig.type.weekend')}</th><th>{t('fastconfig.type.high_season')}</th></tr></thead>
                                        <tbody>{preview!.types.map((ty) => (
                                            <tr key={ty.code} className="border-t border-slate-200"><td className="py-1 pr-2 font-semibold">{ty.code}</td><td className="pr-2">{ty.rooms}</td><td className="pr-2">{formatCurrency(ty.base_price)}</td>
                                                <td className="pr-2">{formatCurrency((ty as unknown as { weekend_price: number }).weekend_price)}</td><td>{formatCurrency((ty as unknown as { high_season_price: number }).high_season_price)}</td></tr>
                                        ))}</tbody>
                                    </table>
                                </div>
                            </>
                        )}
                    </section>

                    {report && <ReportView report={report} />}
                </div>
            </div>

            {/* action bar: sticky on phones, inline on desktop */}
            <div className="fixed inset-x-0 bottom-[calc(var(--bar-bottom-h)+var(--safe-bottom))] z-20 md:bottom-0 md:left-[72px] border-t border-slate-300 bg-white p-3 lg:static lg:mt-4 lg:border-0 lg:bg-transparent lg:p-0">
                <div className="mx-auto flex max-w-screen-2xl flex-wrap items-center justify-end gap-2">
                    <p className="mr-auto text-sm text-slate-700" aria-live="polite">{tot ? t('fastconfig.bar', { rooms: tot.rooms, revenue: formatCurrency(tot.revenue_100) }) : ''}</p>
                    <Button variant="secondary" disabled={!canApply} loading={busy === 'dry'} onClick={() => run(true)}>{t('fastconfig.dry_run')}</Button>
                    {!confirm
                        ? <Button disabled={!canApply} onClick={() => setConfirm(true)}>{t('fastconfig.apply')}</Button>
                        : <><Button variant="ghost" onClick={() => setConfirm(false)}>{t('fastconfig.cancel')}</Button><Button loading={busy === 'apply'} onClick={() => run(false)}>{t('fastconfig.apply.confirm', { rooms: tot?.rooms ?? 0 })}</Button></>}
                </div>
            </div>
        </AppLayout>
    );
}

function ReportView({ report }: { report: Report }) {
    const t = useT();
    const rows: [string, Section][] = [['room_types', report.room_types], ['rooms', report.rooms], ['rate_plans', report.rate_plans], ['outlets', report.outlets], ['stores', report.stores]];
    return (
        <section className="card p-4" aria-live="polite" aria-labelledby="fc-rep">
            <h2 id="fc-rep" className="text-base font-semibold text-slate-900">{report.dry_run ? t('fastconfig.report.dry') : t('fastconfig.report.done')}</h2>
            <p className="mb-2 text-sm text-slate-700">{report.hotel.name} ({report.hotel.code}){report.hotel.created ? ` — ${t('fastconfig.report.new_hotel')}` : ''}</p>
            <div className="table-wrap">
                <table className="table-auto w-full text-sm">
                    <thead><tr className="text-left text-slate-600"><th className="py-1 pr-2" /><th className="pr-2">{t('fastconfig.report.created')}</th><th className="pr-2">{t('fastconfig.report.updated')}</th><th className="pr-2">{t('fastconfig.report.skipped')}</th><th className="pr-2">{t('fastconfig.report.blocked')}</th><th>{t('fastconfig.report.removed')}</th></tr></thead>
                    <tbody>{rows.map(([key, s]) => (
                        <tr key={key} className="border-t border-slate-200"><td className="py-1 pr-2 font-semibold">{t(`fastconfig.report.${key}`)}</td><td className="pr-2">{s.created.length}</td><td className="pr-2">{s.updated.length}</td><td className="pr-2">{s.skipped.length}</td><td className="pr-2">{s.blocked.length}</td><td>{s.removed.length}</td></tr>
                    ))}</tbody>
                </table>
            </div>
            {report.blocked.length > 0 && (
                <ul className="mt-3 space-y-1">{report.blocked.map((b, i) => <li key={i} className="rounded-md border border-red-300 bg-red-50 px-3 py-2 text-sm text-red-800">{b.kind} {b.code}: {b.reason}</li>)}</ul>
            )}
        </section>
    );
}
