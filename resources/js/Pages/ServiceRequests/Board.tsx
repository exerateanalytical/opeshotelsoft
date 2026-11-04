import AppLayout from '@/Layouts/AppLayout';
import QueryStatus from '@/Components/ui/QueryStatus';
import { Head, Link, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { api, ApiError, useApi } from '@/lib/api';
import { useLocale, useT } from '@/lib/i18n';

interface Req {
    id: number;
    room_number: string;
    category: string;
    department: string;
    items: (string | { code?: string; label?: string; qty?: number })[];
    note: string | null;
    priority: string;
    source: string;
    status: string;
    due_at: string;
    assignee?: { id: number; name: string } | null;
}
interface Catalog {
    categories: { code: string; department: string; sla_minutes: number }[];
    departments: string[];
    items: { code: string; category: string; fr: string; en: string }[];
    staff: { id: number; name: string }[];
}

export default function Board() {
    const t = useT();
    const locale = useLocale();
    const perms = (usePage().props as { auth?: { user?: { permissions?: string[] } | null } }).auth?.user?.permissions ?? [];
    const canManage = perms.includes('servicerequests.manage');
    const [dept, setDept] = useState('');
    const [open, setOpen] = useState(false);
    const [msg, setMsg] = useState<{ ok: boolean; text: string } | null>(null);
    const [now, setNow] = useState(Date.now());
    const qs = dept ? `&department=${dept}` : '';
    const active = useApi<{ data: Req[] }>(`service-requests?status=active${qs}`, [dept]);
    const done = useApi<{ data: Req[] }>(`service-requests?status=done${qs}`, [dept]);
    const catalog = useApi<Catalog>('service-requests/catalog');

    useEffect(() => {
        const id = setInterval(() => { setNow(Date.now()); active.reload(); done.reload(); }, 10000);
        return () => clearInterval(id);
    });

    const itemLabel = (i: Req['items'][number]) => {
        const code = typeof i === 'string' ? i : i.code ?? i.label ?? '';
        const c = catalog.data?.items.find((x) => x.code === code);
        const base = c ? c[locale] : typeof i === 'string' ? i : i.label ?? code;
        return typeof i !== 'string' && (i.qty ?? 1) > 1 ? `${i.qty} ${base}` : base;
    };

    async function act(r: Req, action: string, body: object = {}) {
        try {
            await api(`service-requests/${r.id}/${action}`, { method: 'POST', body: JSON.stringify(body) });
            setMsg(null);
            active.reload();
            done.reload();
        } catch (e) {
            setMsg({ ok: false, text: e instanceof ApiError ? e.message : t('servicerequests.error.generic') });
        }
    }

    function sla(r: Req) {
        const mins = Math.round((new Date(r.due_at).getTime() - now) / 60000);
        return mins >= 0
            ? { text: t('servicerequests.sla.left', { minutes: mins }), cls: mins < 5 ? 'text-amber-700' : 'text-slate-600' }
            : { text: t('servicerequests.sla.late', { minutes: -mins }), cls: 'text-red-700 font-semibold' };
    }

    const list = active.data?.data ?? [];
    const cols: { key: string; title: string; rows: Req[] }[] = [
        { key: 'new', title: t('servicerequests.col.new'), rows: list.filter((r) => r.status === 'new') },
        { key: 'progress', title: t('servicerequests.col.progress'), rows: list.filter((r) => r.status !== 'new') },
        { key: 'done', title: t('servicerequests.col.done'), rows: done.data?.data ?? [] },
    ];

    return (
        <AppLayout title={t('servicerequests.title')} subtitle={t('servicerequests.subtitle')}
            actions={<>
                <Link href="/service-requests/settings" className="btn-secondary">{t('servicerequests.settings')}</Link>
                {canManage && <button type="button" className="btn-primary" onClick={() => setOpen(!open)}>{t('servicerequests.new')}</button>}
            </>}>
            <Head title={t('servicerequests.title')} />
            <QueryStatus query={active} variant="cards" />
            <div className="mb-3 flex flex-wrap gap-2">
                <select className="select" value={dept} onChange={(e) => setDept(e.target.value)} aria-label={t('servicerequests.filter.department')}>
                    <option value="">{t('servicerequests.filter.all')}</option>
                    {catalog.data?.departments.map((d) => <option key={d} value={d}>{t(`servicerequests.dept.${d}`)}</option>)}
                </select>
            </div>
            {msg && <p className={`mb-3 text-sm ${msg.ok ? 'text-emerald-700' : 'text-red-700'}`}>{msg.text}</p>}
            {open && canManage && catalog.data && <NewRequest catalog={catalog.data} locale={locale} onDone={() => { setOpen(false); active.reload(); }} />}
            <div className="grid gap-4 lg:grid-cols-3">
                {cols.map((c) => (
                    <section key={c.key}>
                        <h2 className="mb-2 text-sm font-semibold text-slate-700">{c.title} ({c.rows.length})</h2>
                        {c.rows.length === 0 && <p className="text-sm text-slate-500">{t('servicerequests.empty')}</p>}
                        <ul className="space-y-2">
                            {c.rows.map((r) => (
                                <li key={r.id} className="card p-3 text-sm">
                                    <div className="flex items-baseline justify-between gap-2">
                                        <span className="text-lg font-semibold text-slate-900">{t('servicerequests.room', { room: r.room_number })}</span>
                                        {r.priority !== 'normal' && <span className={`text-[13px] font-semibold ${r.priority === 'urgent' ? 'text-red-700' : 'text-amber-700'}`}>{t(`servicerequests.priority.${r.priority}`)}</span>}
                                    </div>
                                    <p className="text-slate-700">{t(`servicerequests.cat.${r.category}`)} - {t(`servicerequests.dept.${r.department}`)}</p>
                                    {r.items.length > 0 && <p className="text-slate-900">{r.items.map(itemLabel).join(', ')}</p>}
                                    {r.note && <p className="text-slate-600">{r.note}</p>}
                                    <p className="text-[13px] text-slate-500">{t(`servicerequests.source.${r.source}`)}{r.assignee ? ` - ${r.assignee.name}` : ''}</p>
                                    {['new', 'acknowledged', 'in_progress'].includes(r.status) && <p className={`text-[13px] ${sla(r).cls}`}>{sla(r).text}</p>}
                                    {canManage && ['new', 'acknowledged', 'in_progress'].includes(r.status) && (
                                        <div className="mt-2 flex flex-wrap items-center gap-2">
                                            {r.status === 'new' && <button type="button" className="btn-secondary" onClick={() => act(r, 'acknowledge')}>{t('servicerequests.action.acknowledge')}</button>}
                                            {r.status !== 'in_progress' && <button type="button" className="btn-secondary" onClick={() => act(r, 'start')}>{t('servicerequests.action.start')}</button>}
                                            <select className="select" value="" aria-label={t('servicerequests.action.assign')} onChange={(e) => e.target.value && act(r, 'assign', { user_id: Number(e.target.value) })}>
                                                <option value="">{t('servicerequests.action.assign')}</option>
                                                {catalog.data?.staff.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
                                            </select>
                                            <button type="button" className="btn-primary" onClick={() => act(r, 'complete', { note: window.prompt(t('servicerequests.action.note')) || null })}>{t('servicerequests.action.complete')}</button>
                                            <button type="button" className="btn-secondary" onClick={() => act(r, 'cancel')}>{t('servicerequests.action.cancel')}</button>
                                        </div>
                                    )}
                                </li>
                            ))}
                        </ul>
                    </section>
                ))}
            </div>
        </AppLayout>
    );
}

function NewRequest({ catalog, locale, onDone }: { catalog: Catalog; locale: 'fr' | 'en'; onDone: () => void }) {
    const t = useT();
    const [f, setF] = useState({ room_number: '', category: 'housekeeping', note: '', priority: 'normal', source: 'room_phone' });
    const [items, setItems] = useState<string[]>([]);
    const [err, setErr] = useState<string | null>(null);
    const options = catalog.items.filter((i) => i.category === f.category);

    async function submit(e: React.FormEvent) {
        e.preventDefault();
        try {
            await api('service-requests', { method: 'POST', body: JSON.stringify({ ...f, items, note: f.note || null }) });
            onDone();
        } catch (x) {
            setErr(x instanceof ApiError ? x.message : t('servicerequests.error.generic'));
        }
    }

    return (
        <form onSubmit={submit} className="card mb-4 grid gap-3 p-4 text-sm sm:grid-cols-2">
            <h2 className="font-semibold sm:col-span-2">{t('servicerequests.form.title')}</h2>
            <label className="grid gap-1">{t('servicerequests.form.room')}
                <input className="input" required maxLength={20} value={f.room_number} onChange={(e) => setF({ ...f, room_number: e.target.value })} />
            </label>
            <label className="grid gap-1">{t('servicerequests.form.category')}
                <select className="select" value={f.category} onChange={(e) => { setF({ ...f, category: e.target.value }); setItems([]); }}>
                    {catalog.categories.map((c) => <option key={c.code} value={c.code}>{t(`servicerequests.cat.${c.code}`)}</option>)}
                </select>
            </label>
            {options.length > 0 && (
                <fieldset className="sm:col-span-2">
                    <legend className="mb-1">{t('servicerequests.form.items')}</legend>
                    <div className="flex flex-wrap gap-3">
                        {options.map((o) => (
                            <label key={o.code} className="flex items-center gap-1">
                                <input type="checkbox" checked={items.includes(o.code)} onChange={(e) => setItems(e.target.checked ? [...items, o.code] : items.filter((x) => x !== o.code))} />
                                {o[locale]}
                            </label>
                        ))}
                    </div>
                </fieldset>
            )}
            <label className="grid gap-1 sm:col-span-2">{t('servicerequests.form.note')}
                <input className="input" maxLength={1000} value={f.note} onChange={(e) => setF({ ...f, note: e.target.value })} />
            </label>
            <label className="grid gap-1">{t('servicerequests.form.priority')}
                <select className="select" value={f.priority} onChange={(e) => setF({ ...f, priority: e.target.value })}>
                    {['low', 'normal', 'high', 'urgent'].map((p) => <option key={p} value={p}>{t(`servicerequests.priority.${p}`)}</option>)}
                </select>
            </label>
            <label className="grid gap-1">{t('servicerequests.form.source')}
                <select className="select" value={f.source} onChange={(e) => setF({ ...f, source: e.target.value })}>
                    {['room_phone', 'reception', 'staff'].map((s) => <option key={s} value={s}>{t(`servicerequests.source.${s}`)}</option>)}
                </select>
            </label>
            {err && <p className="text-red-700 sm:col-span-2">{err}</p>}
            <div className="sm:col-span-2"><button className="btn-primary" type="submit">{t('servicerequests.form.submit')}</button></div>
        </form>
    );
}
