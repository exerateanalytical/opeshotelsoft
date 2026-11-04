import { useState } from 'react';
import Button from '@/Components/ui/Button';
import DataTable from '@/Components/ui/DataTable';
import EmptyState from '@/Components/ui/EmptyState';
import Field from '@/Components/ui/Field';
import Modal from '@/Components/ui/Modal';
import { toast } from '@/Components/ui/Toast';
import { api, useApi } from '@/lib/api';
import { useT } from '@/lib/i18n';
import { errMsg, fcfa, num, Ref } from './ControlShared';
import { useList } from './StockForms';

interface Review { id: number; outlet_id: number; store_id: number; period_from: string; period_to: string; variance_value: number; status: string }
interface Line { id: number; stock_item_id: number; theoretical: number; actual: number; variance_qty: number; unexplained_qty: number; variance_value: number; reason_code: string | null; note: string | null }
interface Detail extends Review { lines: Line[] }

export const VARIANCE_REASONS = ['portioning', 'spillage', 'theft', 'count_error', 'unrecorded_wastage', 'recipe_outdated', 'supplier_shortweight', 'transfer_unrecorded', 'other', 'count_shortfall', 'count_surplus', 'recipe_drift'];
const day = (d: string) => String(d).slice(0, 10);

/** GAPC: saved variance reviews, with detail, per-line annotation and finalisation. */
export default function VarianceReviews({ outletId, canManage, refreshKey }: { outletId: string; canManage: boolean; refreshKey: number }) {
    const t = useT();
    const list = useApi<Review[]>(`stock-control/variance/reviews${outletId ? `?outlet_id=${outletId}` : ''}`, [outletId, refreshKey]);
    const items = useList<Ref>('stock/items?per_page=500&sort=name');
    const [openId, setOpenId] = useState<number | null>(null);
    const detail = useApi<Detail>(openId ? `stock-control/variance/reviews/${openId}` : null, [openId]);
    const [draft, setDraft] = useState<Record<number, { reason: string; note: string }>>({});
    const [busy, setBusy] = useState<number | 'final' | null>(null);
    const [err, setErr] = useState<string | null>(null);
    const d = detail.data;
    const final = d?.status === 'final';
    const iName = (id: number) => items.rows.find((i) => i.id === id)?.name ?? `#${id}`;
    const cur = (l: Line) => draft[l.id] ?? { reason: l.reason_code ?? '', note: l.note ?? '' };

    function close() { setOpenId(null); setDraft({}); setErr(null); }
    async function saveLine(l: Line) {
        const v = cur(l);
        setBusy(l.id);
        setErr(null);
        try {
            await api(`stock-control/variance/reviews/${openId}/lines/${l.id}`, { method: 'PUT', body: JSON.stringify({ reason_code: v.reason || null, note: v.note || null }) });
            toast.success(t('gapc.vr.line_saved'));
            setDraft((x) => { const n = { ...x }; delete n[l.id]; return n; });
            detail.reload();
        } catch (e) { setErr(errMsg(e)); } finally { setBusy(null); }
    }
    async function finalize() {
        if (!window.confirm(t('gapc.vr.confirm_final'))) return;
        setBusy('final');
        setErr(null);
        try {
            await api(`stock-control/variance/reviews/${openId}/finalize`, { method: 'POST', body: '{}' });
            toast.success(t('gapc.vr.finalized'));
            detail.reload();
            list.reload();
        } catch (e) { setErr(errMsg(e)); } finally { setBusy(null); }
    }

    return (
        <section className="card mt-4 p-3" aria-label={t('gapc.vr.title')}>
            <h2 className="mb-1 text-lg font-semibold">{t('gapc.vr.title')}</h2>
            <p className="mb-3 text-sm text-slate-500">{t('gapc.vr.hint')}</p>
            <DataTable<Review> caption={t('gapc.vr.title')} rows={list.data ?? []} rowKey={(r) => r.id} loading={list.loading}
                empty={<EmptyState title={t('gapc.vr.empty')} description={t('gapc.vr.empty_hint')} />}
                columns={[
                    { key: 'id', header: t('gapc.vr.no'), cell: (r) => `#${r.id}`, hideLabel: true },
                    { key: 'period', header: t('gapc.vr.period'), cell: (r) => `${day(r.period_from)} - ${day(r.period_to)}` },
                    { key: 'value', header: t('gapc.vr.value'), align: 'right', cell: (r) => fcfa(r.variance_value) },
                    { key: 'status', header: t('gapc.vr.status'), cell: (r) => <span className={r.status === 'final' ? 'badge-green' : 'badge-yellow'}>{t(`gapc.vr.st.${r.status}`)}</span> },
                    { key: 'act', header: t('gapc.col.actions'), required: true, cell: (r) => <Button size="sm" variant="secondary" onClick={() => setOpenId(r.id)}>{t('gapc.vr.open')}</Button> },
                ]} />
            <Modal open={!!openId} onClose={close} size="xl" title={d ? t('gapc.vr.detail_title', { id: d.id, from: day(d.period_from), to: day(d.period_to) }) : t('gapc.vr.title')}
                footer={<><Button variant="secondary" onClick={close}>{t('gapc.close')}</Button>{canManage && d && !final && <Button loading={busy === 'final'} onClick={finalize}>{t('gapc.vr.finalize')}</Button>}</>}>
                {err && <p role="alert" className="field-error mb-2">{err}</p>}
                {d && (
                    <>
                        <p className="mb-3 text-sm">{t('gapc.vr.total')}: <b>{fcfa(d.variance_value)}</b> <span className={final ? 'badge-green ml-2' : 'badge-yellow ml-2'}>{t(`gapc.vr.st.${d.status}`)}</span></p>
                        {final && <p className="mb-3 text-sm text-slate-500">{t('gapc.vr.locked')}</p>}
                        <DataTable<Line> caption={t('gapc.vr.lines')} rows={d.lines} rowKey={(l) => l.id}
                            empty={<EmptyState title={t('gapc.vr.no_lines')} />}
                            columns={[
                                { key: 'item', header: t('gapc.col.item'), cell: (l) => iName(l.stock_item_id), hideLabel: true },
                                { key: 'th', header: t('gapc.vr.theoretical'), align: 'right', cell: (l) => num(l.theoretical) },
                                { key: 'ac', header: t('gapc.vr.actual'), align: 'right', cell: (l) => num(l.actual) },
                                { key: 'var', header: t('gapc.vr.variance'), align: 'right', cell: (l) => <span className={l.variance_value > 0 ? 'text-red-700' : ''}>{num(l.variance_qty)} ({fcfa(l.variance_value)})</span> },
                                { key: 'reason', header: t('gapc.vr.reason'), cell: (l) => (canManage && !final ? (
                                    <select className="select min-w-40" aria-label={t('gapc.vr.reason_for', { name: iName(l.stock_item_id) })} value={cur(l).reason} onChange={(e) => setDraft({ ...draft, [l.id]: { ...cur(l), reason: e.target.value } })}>
                                        <option value="">-</option>{VARIANCE_REASONS.map((r) => <option key={r} value={r}>{t(`gapc.vr.reason.${r}`)}</option>)}
                                    </select>
                                ) : (l.reason_code ? t(`gapc.vr.reason.${l.reason_code}`) : '-')) },
                                { key: 'note', header: t('gapc.vr.note'), cell: (l) => (canManage && !final ? (
                                    <input className="input min-w-48" maxLength={255} aria-label={t('gapc.vr.note_for', { name: iName(l.stock_item_id) })} value={cur(l).note} onChange={(e) => setDraft({ ...draft, [l.id]: { ...cur(l), note: e.target.value } })} />
                                ) : (l.note ?? '-')) },
                                ...(canManage && !final ? [{ key: 'act', header: t('gapc.col.actions'), required: true, cell: (l: Line) => (
                                    <Button size="sm" disabled={!draft[l.id]} loading={busy === l.id} onClick={() => saveLine(l)}>{t('gapc.save')}</Button>
                                ) }] : []),
                            ]} />
                    </>
                )}
            </Modal>
        </section>
    );
}
