import AppLayout from '@/Layouts/AppLayout';
import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, Plus, Trash2 } from 'lucide-react';
import { useEffect, useState } from 'react';
import Alert from '@/Components/ui/Alert';
import EmptyState from '@/Components/ui/EmptyState';
import QueryStatus from '@/Components/ui/QueryStatus';
import { api, useApi } from '@/lib/api';
import { useT } from '@/lib/i18n';
import { errText } from '@/Components/gape/blob';

interface Rule { kind: 'late' | 'early' | 'early_checkin'; after_hour: number | null; charge_code: string; fee_type: 'percent_of_night' | 'fixed'; value: number; label: string | null; active: boolean }
interface Code { code: string; name: string }

const blank = (): Rule => ({ kind: 'late', after_hour: 12, charge_code: '', fee_type: 'percent_of_night', value: 50, label: '', active: true });

/** Table 2 row 9: late checkout / early departure / early check-in fee rules (front-desk/checkout-fee-rules). */
export default function CheckoutFeeRules() {
    const t = useT();
    const q = useApi<Rule[]>('front-desk/checkout-fee-rules');
    const codes = useApi<Code[]>('setup/charge-codes');
    const [rules, setRules] = useState<Rule[]>([]);
    const [busy, setBusy] = useState(false);
    const [msg, setMsg] = useState<{ ok: boolean; text: string } | null>(null);
    useEffect(() => { if (q.data) setRules(q.data.map((r) => ({ ...r, label: r.label ?? '' }))); }, [q.data]);
    const set = (i: number, patch: Partial<Rule>) => setRules(rules.map((r, j) => (j === i ? { ...r, ...patch } : r)));

    return (
        <AppLayout title={t('gape.fees.title')} subtitle={t('gape.fees.subtitle')}
            actions={<Link href="/front-desk" className="btn-secondary"><ArrowLeft className="h-4 w-4" aria-hidden="true" /> {t('gape.fees.back')}</Link>}>
            <Head title={t('gape.fees.title')} />
            <form className="mx-auto max-w-5xl space-y-4" onSubmit={async (e) => {
                e.preventDefault();
                setBusy(true); setMsg(null);
                try {
                    const body = { rules: rules.map((r) => ({ ...r, after_hour: r.kind === 'late' ? Number(r.after_hour ?? 0) : null, value: Number(r.value), label: r.label || null })) };
                    await api('front-desk/checkout-fee-rules', { method: 'PUT', body: JSON.stringify(body) });
                    setMsg({ ok: true, text: t('gape.fees.saved') });
                    q.reload();
                } catch (err) { setMsg({ ok: false, text: errText(err) }); } finally { setBusy(false); }
            }}>
                {msg && <Alert variant={msg.ok ? 'success' : 'danger'}>{msg.text}</Alert>}
                <QueryStatus query={q} variant="form" rows={3} />
                {q.data && rules.length === 0 && <EmptyState title={t('gape.fees.empty')} description={t('gape.fees.empty_text')} action={<button type="button" className="btn-primary" onClick={() => setRules([blank()])}><Plus className="h-4 w-4" aria-hidden="true" /> {t('gape.fees.add')}</button>} />}
                {rules.length > 0 && (
                    <div className="card table-wrap">
                        <table className="table-auto table-cards">
                            <caption className="sr-only">{t('gape.fees.title')}</caption>
                            <thead><tr>
                                <th>{t('gape.fees.kind')}</th><th>{t('gape.fees.after_hour')}</th><th>{t('gape.fees.charge_code')}</th><th>{t('gape.fees.fee_type')}</th>
                                <th className="text-right">{t('gape.fees.value')}</th><th>{t('gape.fees.label')}</th><th>{t('gape.fees.active')}</th><th><span className="sr-only">{t('gape.actions')}</span></th>
                            </tr></thead>
                            <tbody>
                                {rules.map((r, i) => (
                                    <tr key={i}>
                                        <td data-label={t('gape.fees.kind')}>
                                            <select className="select" aria-label={t('gape.fees.kind')} value={r.kind} onChange={(e) => set(i, { kind: e.target.value as Rule['kind'] })}>
                                                {(['late', 'early', 'early_checkin'] as const).map((k) => <option key={k} value={k}>{t(`gape.fees.kind_${k}`)}</option>)}
                                            </select>
                                        </td>
                                        <td data-label={t('gape.fees.after_hour')}>
                                            {r.kind === 'late'
                                                ? <input className="input w-24" type="number" min={0} max={23} required aria-label={t('gape.fees.after_hour')} value={r.after_hour ?? ''} onChange={(e) => set(i, { after_hour: e.target.value === '' ? null : +e.target.value })} />
                                                : <span className="text-slate-500">-</span>}
                                        </td>
                                        <td data-label={t('gape.fees.charge_code')}>
                                            <select className="select" required aria-label={t('gape.fees.charge_code')} value={r.charge_code} onChange={(e) => set(i, { charge_code: e.target.value })}>
                                                <option value="">-</option>
                                                {codes.data?.map((c) => <option key={c.code} value={c.code}>{c.code} · {c.name}</option>)}
                                            </select>
                                        </td>
                                        <td data-label={t('gape.fees.fee_type')}>
                                            <select className="select" aria-label={t('gape.fees.fee_type')} value={r.fee_type} onChange={(e) => set(i, { fee_type: e.target.value as Rule['fee_type'] })}>
                                                <option value="percent_of_night">{t('gape.fees.percent')}</option>
                                                <option value="fixed">{t('gape.fees.fixed')}</option>
                                            </select>
                                        </td>
                                        <td data-label={t('gape.fees.value')}><input className="input w-28 text-right" type="number" min={1} max={r.fee_type === 'percent_of_night' ? 100 : undefined} required aria-label={t('gape.fees.value')} value={r.value} onChange={(e) => set(i, { value: +e.target.value })} /></td>
                                        <td data-label={t('gape.fees.label')}><input className="input" maxLength={60} aria-label={t('gape.fees.label')} value={r.label ?? ''} onChange={(e) => set(i, { label: e.target.value })} /></td>
                                        <td data-label={t('gape.fees.active')}><input type="checkbox" aria-label={t('gape.fees.active')} checked={r.active} onChange={(e) => set(i, { active: e.target.checked })} /></td>
                                        <td><button type="button" className="btn-ghost btn-icon text-red-700" aria-label={t('gape.fees.remove')} title={t('gape.fees.remove')} onClick={() => setRules(rules.filter((_, j) => j !== i))}><Trash2 className="h-4 w-4" aria-hidden="true" /></button></td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
                {q.data && (
                    <div className="btn-row">
                        {rules.length > 0 && <button type="button" className="btn-secondary" disabled={rules.length >= 20} onClick={() => setRules([...rules, blank()])}><Plus className="h-4 w-4" aria-hidden="true" /> {t('gape.fees.add')}</button>}
                        <button className="btn-primary" disabled={busy}>{t('gape.save')}</button>
                    </div>
                )}
                <p className="text-sm text-slate-500">{t('gape.fees.help')}</p>
            </form>
        </AppLayout>
    );
}
