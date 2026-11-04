import AppLayout from '@/Layouts/AppLayout';
import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, Download } from 'lucide-react';
import { useState } from 'react';
import EmptyState from '@/Components/ui/EmptyState';
import Modal from '@/Components/ui/Modal';
import { toast } from '@/Components/ui/Toast';
import QueryStatus from '@/Components/ui/QueryStatus';
import { api, ApiError, useApi } from '@/lib/api';
import { formatCurrency } from '@/lib/utils';
import { useT } from '@/lib/i18n';

interface Item {
    id: number; employee_name: string; channel: string; destination: string | null; bank_name: string | null; amount: number;
    status: string; attempts: number; error: string | null;
}
interface Run { id: number; period: string; status: string; total: number; paid: number; items: Item[] }

const BADGE: Record<string, string> = { succeeded: 'badge-green', failed: 'badge-red', pending: 'badge-slate', exported: 'badge-yellow', sent: 'badge-yellow' };

export default function PayoutShow({ id }: { id: number }) {
    const t = useT();
    const run = useApi<Run>(`payments/payouts/${id}`);
    const [error, setError] = useState('');
    const [fix, setFix] = useState<Record<number, string>>({});
    const [picked, setPicked] = useState<number[]>([]);
    const [rejecting, setRejecting] = useState(false);
    const [reason, setReason] = useState('');
    const reject = async () => {
        setError('');
        try {
            await api(`payments/payouts/${id}/reject`, { method: 'POST', body: JSON.stringify({ item_ids: picked, reason }) });
            toast.success(t('gapf.payout.rejected'));
            setRejecting(false); setPicked([]); setReason('');
        } catch (e) {
            setError(e instanceof ApiError ? e.message : String(e));
            setRejecting(false);
        }
        run.reload();
    };

    const act = async (path: string, init: RequestInit = { method: 'POST' }) => {
        setError('');
        try {
            await api(path, init);
        } catch (e) {
            setError(e instanceof ApiError ? e.message : String(e));
        }
        run.reload();
    };
    const r = run.data;

    return (
        <AppLayout title={`${t('payments2.payouts.title')} ${r?.period ?? ''}`}
            actions={<Link href="/payments/payouts" className="btn-secondary"><ArrowLeft className="w-4 h-4" /> {t('payments2.common.back')}</Link>}>
            <Head title={t('payments2.payouts.title')} />
            {!r ? <QueryStatus query={run} variant="detail" /> : (
                <>
                    <div className="card p-4 mb-4 flex flex-wrap items-center gap-3">
                        <span>{t('payments2.payouts.total')}: <b>{formatCurrency(r.total)}</b></span>
                        <span>{t('payments2.payouts.paid')}: <b>{formatCurrency(r.paid)}</b></span>
                        <span className={`badge ${r.status === 'completed' ? 'badge-green' : 'badge-yellow'}`}>{t(`payments2.status.${r.status}`)}</span>
                        {r.status !== 'completed' && (
                            <div className="flex flex-wrap gap-2 ml-auto">
                                <button className="btn-primary" onClick={() => act(`payments/payouts/${id}/process`)}>{t('payments2.payouts.process')}</button>
                                <button className="btn-secondary" onClick={() => act(`payments/payouts/${id}/process`, { method: 'POST', body: JSON.stringify({ retry_failed: true }) })}>{t('payments2.payouts.retry')}</button>
                                <a className="btn-secondary" href={`/api/v1/payments/payouts/${id}/bank-file`} onClick={() => setTimeout(run.reload, 1500)}><Download className="w-4 h-4" /> {t('payments2.payouts.bank_file')}</a>
                                <button className="btn-secondary" onClick={() => act(`payments/payouts/${id}/confirm`)}>{t('payments2.payouts.confirm')}</button>
                                <button className="btn-danger" disabled={picked.length === 0} onClick={() => setRejecting(true)}>{t('gapf.payout.reject', { count: picked.length })}</button>
                            </div>
                        )}
                        {error && <div className="w-full text-red-600">{error}</div>}
                    </div>
                    <div className="card overflow-x-auto">
                        <table className="table-auto">
                            <thead><tr><th><span className="sr-only">{t('gapf.payout.select')}</span></th><th>{t('payments2.payouts.employee')}</th><th>{t('payments2.payouts.channel')}</th><th>{t('payments2.payouts.destination')}</th>
                                <th className="text-right">{t('payments2.inbound.amount')}</th><th>{t('payments2.payouts.attempts')}</th><th></th><th>{t('payments2.payouts.error')}</th></tr></thead>
                            <tbody>
                                {r.items.map((i) => (
                                    <tr key={i.id}>
                                        <td>{i.status !== 'succeeded' && r.status !== 'completed' && <input type="checkbox" aria-label={t('gapf.payout.select_item', { name: i.employee_name })} checked={picked.includes(i.id)} onChange={(e) => setPicked(e.target.checked ? [...picked, i.id] : picked.filter((x) => x !== i.id))} />}</td>
                                        <td>{i.employee_name}</td>
                                        <td>{t(`payments2.channel.${i.channel}`)}</td>
                                        <td className="font-mono">{i.bank_name ? `${i.bank_name} · ` : ''}{i.destination ?? '-'}</td>
                                        <td className="text-right">{formatCurrency(i.amount)}</td>
                                        <td>{i.attempts}</td>
                                        <td><span className={`badge ${BADGE[i.status] ?? 'badge-slate'}`}>{t(`payments2.status.${i.status}`)}</span></td>
                                        <td>
                                            {i.error && <span className="text-red-600">{i.error}</span>}
                                            {i.status === 'failed' && (
                                                <span className="flex gap-2 mt-1">
                                                    <input className="input w-36" value={fix[i.id] ?? i.destination ?? ''} onChange={(e) => setFix({ ...fix, [i.id]: e.target.value })} />
                                                    <button className="btn-secondary" onClick={() => act(`payments/payouts/${id}/items/${i.id}`, { method: 'PUT', body: JSON.stringify({ destination: fix[i.id] ?? i.destination }) })}>{t('payments2.payouts.fix')}</button>
                                                </span>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                        {r.items.length === 0 && <EmptyState title={t('states3.empty.payout_items.title')} description={t('states3.empty.payout_items.text')} />}
                    </div>
                </>
            )}
            <Modal open={rejecting} onClose={() => setRejecting(false)} title={t('gapf.payout.reject_title')} formId="reject-form"
                footer={<button type="submit" form="reject-form" className="btn-danger" disabled={!reason.trim()}>{t('gapf.payout.reject_confirm')}</button>}>
                <form id="reject-form" onSubmit={(e) => { e.preventDefault(); reject(); }} className="space-y-3">
                    <p className="text-sm">{t('gapf.payout.reject_count', { count: picked.length })}</p>
                    <label className="block"><span className="label">{t('gapf.payout.reason')}</span><input className="input w-full" maxLength={250} value={reason} onChange={(e) => setReason(e.target.value)} /></label>
                </form>
            </Modal>
        </AppLayout>
    );
}
