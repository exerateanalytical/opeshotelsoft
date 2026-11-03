import AppLayout from '@/Layouts/AppLayout';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { api, ApiError, useApi } from '@/lib/api';
import { formatCurrency } from '@/lib/utils';
import { useT } from '@/lib/i18n';

interface Run { id: number; period: string; status: string; total: number; paid: number }
interface Payroll { id: number; period: string; net: number }

export default function Payouts() {
    const t = useT();
    const data = useApi<{ runs: Run[]; payable: Payroll[] }>('payments/payouts');
    const [error, setError] = useState('');

    const start = async (id: number) => {
        setError('');
        try {
            const r = await api<Run>('payments/payouts', { method: 'POST', body: JSON.stringify({ payroll_run_id: id }) });
            router.visit(`/payments/payouts/${r.id}`);
        } catch (e) {
            setError(e instanceof ApiError ? e.message : String(e));
        }
    };

    return (
        <AppLayout title={t('payments2.payouts.title')} subtitle={t('payments2.payouts.subtitle')}>
            <Head title={t('payments2.payouts.title')} />
            {error && <div className="card p-3 mb-4 text-red-600">{error}</div>}
            <div className="card p-4 mb-4">
                <div className="label">{t('payments2.payouts.payable')}</div>
                {data.data?.payable.length === 0 && <div className="text-slate-500">{t('payments2.payouts.none')}</div>}
                {data.data?.payable.map((p) => (
                    <div key={p.id} className="flex items-center gap-4 py-2 border-t border-slate-100">
                        <span className="font-mono">{p.period}</span><span>{formatCurrency(p.net)}</span>
                        <button className="btn-primary ml-auto" onClick={() => start(p.id)}>{t('payments2.payouts.start')}</button>
                    </div>
                ))}
            </div>
            <div className="card overflow-x-auto">
                <table className="table-auto">
                    <thead><tr><th>{t('payments2.payouts.period')}</th><th className="text-right">{t('payments2.payouts.total')}</th><th className="text-right">{t('payments2.payouts.paid')}</th><th></th></tr></thead>
                    <tbody>
                        {data.data?.runs.map((r) => (
                            <tr key={r.id} className="cursor-pointer" onClick={() => router.visit(`/payments/payouts/${r.id}`)}>
                                <td><Link href={`/payments/payouts/${r.id}`} className="font-mono">{r.period}</Link></td>
                                <td className="text-right">{formatCurrency(r.total)}</td>
                                <td className="text-right">{formatCurrency(r.paid)}</td>
                                <td><span className={`badge ${r.status === 'completed' ? 'badge-green' : 'badge-yellow'}`}>{t(`payments2.status.${r.status}`)}</span></td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </AppLayout>
    );
}
