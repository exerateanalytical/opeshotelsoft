import { useState } from 'react';
import Field from '@/Components/ui/Field';
import Modal from '@/Components/ui/Modal';
import { api } from '@/lib/api';
import { useCan } from '@/lib/useCan';
import { useT } from '@/lib/i18n';
import { formatCurrency } from '@/lib/utils';
import { errText } from './blob';

interface Opt { id: number; name: string; code?: string }
interface Quote { nights: number; total: number; total_before_promo: number; package: { name: string } | null; promo: { code?: string; discount?: number } | null; corporate: { code?: string; name?: string } | null }

const iso = (d: number) => new Date(Date.now() + d * 864e5).toISOString().slice(0, 10);

/** Table 2 row 7: price a stay for a room type and rate plan, with optional promo or corporate code. */
export function QuoteTool({ types, plans }: { types: Opt[]; plans: Opt[] }) {
    const t = useT();
    const [f, setF] = useState({ room_type_id: '', rate_plan_id: '', arrival: iso(0), departure: iso(1), adults: '2', children: '0', promo_code: '', corporate_code: '' });
    const [q, setQ] = useState<Quote | null>(null);
    const [err, setErr] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);

    return (
        <section className="card space-y-3 p-4" aria-labelledby="gape-quote-h">
            <h2 id="gape-quote-h" className="font-medium">{t('gape.quote.title')}</h2>
            <form className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4" onSubmit={async (e) => {
                e.preventDefault();
                setBusy(true); setErr(null); setQ(null);
                const p = new URLSearchParams(Object.entries(f).filter(([, v]) => v !== '') as [string, string][]);
                try { setQ(await api<Quote>(`revenue/quote?${p}`)); } catch (x) { setErr(errText(x)); } finally { setBusy(false); }
            }}>
                <Field label={t('gape.quote.room_type')} required>
                    <select className="select" required value={f.room_type_id} onChange={(e) => setF({ ...f, room_type_id: e.target.value })}>
                        <option value="">-</option>{types.map((x) => <option key={x.id} value={x.id}>{x.name}</option>)}
                    </select>
                </Field>
                <Field label={t('gape.quote.rate_plan')} required>
                    <select className="select" required value={f.rate_plan_id} onChange={(e) => setF({ ...f, rate_plan_id: e.target.value })}>
                        <option value="">-</option>{plans.map((x) => <option key={x.id} value={x.id}>{x.code} · {x.name}</option>)}
                    </select>
                </Field>
                <Field label={t('gape.quote.arrival')} required><input className="input" type="date" required value={f.arrival} onChange={(e) => setF({ ...f, arrival: e.target.value })} /></Field>
                <Field label={t('gape.quote.departure')} required><input className="input" type="date" required min={f.arrival} value={f.departure} onChange={(e) => setF({ ...f, departure: e.target.value })} /></Field>
                <Field label={t('gape.quote.adults')}><input className="input" type="number" min={1} max={8} value={f.adults} onChange={(e) => setF({ ...f, adults: e.target.value })} /></Field>
                <Field label={t('gape.quote.children')}><input className="input" type="number" min={0} max={6} value={f.children} onChange={(e) => setF({ ...f, children: e.target.value })} /></Field>
                <Field label={t('gape.quote.promo')}><input className="input" maxLength={24} value={f.promo_code} onChange={(e) => setF({ ...f, promo_code: e.target.value })} /></Field>
                <Field label={t('gape.quote.corporate')}><input className="input" maxLength={24} value={f.corporate_code} onChange={(e) => setF({ ...f, corporate_code: e.target.value })} /></Field>
                <div className="sm:col-span-2 lg:col-span-4"><button className="btn-primary" disabled={busy}>{t('gape.quote.run')}</button></div>
            </form>
            {err && <p role="alert" className="text-sm text-red-700">{err}</p>}
            {q && (
                <dl role="status" className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm">
                    <dt className="text-slate-500">{t('gape.quote.nights')}</dt><dd>{q.nights}</dd>
                    {q.total_before_promo !== q.total && <><dt className="text-slate-500">{t('gape.quote.before')}</dt><dd className="num text-left">{formatCurrency(q.total_before_promo)}</dd></>}
                    <dt className="text-slate-500">{t('gape.quote.total')}</dt><dd className="font-semibold">{formatCurrency(q.total)}</dd>
                    {q.package && <><dt className="text-slate-500">{t('gape.quote.package')}</dt><dd>{q.package.name}</dd></>}
                    {q.corporate && <><dt className="text-slate-500">{t('gape.quote.corporate')}</dt><dd>{q.corporate.name ?? q.corporate.code}</dd></>}
                </dl>
            )}
        </section>
    );
}

export interface EditableContract { id: number; code: string; name: string; valid_to: string; last_room_availability: boolean; active: boolean; notes?: string | null }

/** Table 2 row 7: edit a corporate contract and check its availability. */
export function ContractActions({ c, types, onSaved }: { c: EditableContract; types: Opt[]; onSaved: () => void }) {
    const t = useT();
    const can = useCan();
    const [edit, setEdit] = useState<null | { name: string; valid_to: string; last_room_availability: boolean; active: boolean; notes: string }>(null);
    const [av, setAv] = useState({ open: false, room_type_id: '', arrival: iso(0), departure: iso(1) });
    const [res, setRes] = useState<{ bookable: boolean; source: string | null; allotment_left: number; general_left: number } | null>(null);
    const [err, setErr] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);

    return (
        <div className="space-y-2">
            <div className="btn-row">
                {can('revenue.manage') && <button type="button" className="btn-secondary btn-sm" onClick={() => { setErr(null); setEdit({ name: c.name, valid_to: c.valid_to.slice(0, 10), last_room_availability: c.last_room_availability, active: c.active, notes: c.notes ?? '' }); }}>{t('gape.contract.edit')}</button>}
                <button type="button" className="btn-secondary btn-sm" aria-expanded={av.open} onClick={() => setAv({ ...av, open: !av.open })}>{t('gape.contract.availability')}</button>
                {!c.active && <span className="badge-slate">{t('gape.contract.inactive')}</span>}
            </div>
            {av.open && (
                <form className="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-4" onSubmit={async (e) => {
                    e.preventDefault(); setErr(null); setRes(null);
                    try { setRes(await api(`revenue/contracts/${c.id}/availability?${new URLSearchParams({ room_type_id: av.room_type_id, arrival: av.arrival, departure: av.departure })}`)); } catch (x) { setErr(errText(x)); }
                }}>
                    <Field label={t('gape.quote.room_type')} required>
                        <select className="select" required value={av.room_type_id} onChange={(e) => setAv({ ...av, room_type_id: e.target.value })}>
                            <option value="">-</option>{types.map((x) => <option key={x.id} value={x.id}>{x.name}</option>)}
                        </select>
                    </Field>
                    <Field label={t('gape.quote.arrival')}><input className="input" type="date" required value={av.arrival} onChange={(e) => setAv({ ...av, arrival: e.target.value })} /></Field>
                    <Field label={t('gape.quote.departure')}><input className="input" type="date" required min={av.arrival} value={av.departure} onChange={(e) => setAv({ ...av, departure: e.target.value })} /></Field>
                    <div className="flex items-end"><button className="btn-primary">{t('gape.contract.check')}</button></div>
                </form>
            )}
            {res && (
                <p role="status" className={res.bookable ? 'text-emerald-700' : 'text-red-700'}>
                    {t(res.bookable ? 'gape.contract.bookable' : 'gape.contract.not_bookable')} · {t('gape.contract.left', { allot: res.allotment_left, general: res.general_left })}
                </p>
            )}
            {err && !edit && <p role="alert" className="text-red-700">{err}</p>}

            <Modal open={!!edit} onClose={() => setEdit(null)} title={t('gape.contract.edit_title', { code: c.code })} formId={`gape-contract-${c.id}`}
                footer={<>
                    <button type="button" className="btn-secondary" onClick={() => setEdit(null)}>{t('gape.cancel')}</button>
                    <button type="submit" form={`gape-contract-${c.id}`} className="btn-primary" disabled={busy}>{t('gape.save')}</button>
                </>}>
                {edit && (
                    <form id={`gape-contract-${c.id}`} className="space-y-3" onSubmit={async (e) => {
                        e.preventDefault(); setBusy(true); setErr(null);
                        try {
                            await api(`revenue/contracts/${c.id}`, { method: 'PATCH', body: JSON.stringify({ ...edit, notes: edit.notes || null }) });
                            setEdit(null); onSaved();
                        } catch (x) { setErr(errText(x)); } finally { setBusy(false); }
                    }}>
                        <Field label={t('gape.contract.name')} required><input className="input" required maxLength={150} value={edit.name} onChange={(e) => setEdit({ ...edit, name: e.target.value })} /></Field>
                        <Field label={t('gape.contract.valid_to')} required><input className="input" type="date" required value={edit.valid_to} onChange={(e) => setEdit({ ...edit, valid_to: e.target.value })} /></Field>
                        <label className="flex min-h-11 items-center gap-2 text-sm"><input type="checkbox" checked={edit.last_room_availability} onChange={(e) => setEdit({ ...edit, last_room_availability: e.target.checked })} /> {t('gape.contract.lra')}</label>
                        <label className="flex min-h-11 items-center gap-2 text-sm"><input type="checkbox" checked={edit.active} onChange={(e) => setEdit({ ...edit, active: e.target.checked })} /> {t('gape.contract.active')}</label>
                        <Field label={t('gape.contract.notes')}><textarea className="textarea" rows={3} maxLength={1000} value={edit.notes} onChange={(e) => setEdit({ ...edit, notes: e.target.value })} /></Field>
                        {err && <p role="alert" className="text-sm text-red-700">{err}</p>}
                    </form>
                )}
            </Modal>
        </div>
    );
}
