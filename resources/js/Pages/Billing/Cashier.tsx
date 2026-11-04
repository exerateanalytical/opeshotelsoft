import Field from '@/Components/ui/Field';
import FilterBar from '@/Components/ui/FilterBar';
import AppLayout from '@/Layouts/AppLayout';
import CashierBar from '@/Components/CashierBar';
import { Head } from '@inertiajs/react';
import { ChevronDown, ChevronRight, Loader2 } from 'lucide-react';
import { useEffect, useState } from 'react';
import { useApi } from '@/lib/api';
import { cn, formatCurrency, formatDate, formatDateTime } from '@/lib/utils';
import { useT } from '@/lib/i18n';
import PrintPdfButton from '@/Components/PrintPdfButton';
import ExportButtons from '@/Components/ExportButtons';
import EmptyState from '@/Components/ui/EmptyState';

interface Shift {
    id: number;
    user_id: number;
    user_name: string | null;
    station: string | null;
    status: string;
    business_date: string | null;
    opened_at: string;
    closed_at: string | null;
    opening_float: number;
    expected: Record<string, number> | null;
    counted: Record<string, number> | null;
    variance: number | null;
}
interface Page<T> { data: T[] }

export default function Cashier() {
    const t = useT();
    const statusLabel = (s: string) => (t(`billing.status.${s}`) === `billing.status.${s}` ? s : t(`billing.status.${s}`));
    const [refresh, setRefresh] = useState(0);
    const [status, setStatus] = useState('');
    const [onlyVariance, setOnlyVariance] = useState(false);
    const [userId, setUserId] = useState('');
    const [from, setFrom] = useState('');
    const [to, setTo] = useState('');
    const [expanded, setExpanded] = useState<number | null>(null);
    const query = new URLSearchParams(Object.entries({ status, user_id: userId, from, to }).filter(([, v]) => v)).toString();
    const shifts = useApi<Page<Shift>>(`cashier-shifts${query ? `?${query}` : ''}`, [refresh]);
    // Cashier choices are collected from the shifts seen so far.
    const [cashiers, setCashiers] = useState<Record<number, string>>({});
    useEffect(() => {
        const seen = Object.fromEntries((shifts.data?.data ?? []).map((s) => [s.user_id, s.user_name ?? t('billing.cashier.user_n', { id: s.user_id })]));
        if (Object.keys(seen).some((k) => !(k in cashiers))) setCashiers((c) => ({ ...c, ...seen }));
    }, [shifts.data]);

    const rows = (shifts.data?.data ?? []).filter((s) => !onlyVariance || (s.variance ?? 0) !== 0);

    return (
        <AppLayout title={t('billing.cashier.title')} subtitle={t('billing.cashier.subtitle')}>
            <Head title={t('billing.cashier.title')} />
            <CashierBar onChange={() => setRefresh((n) => n + 1)} refresh={refresh} />

            <FilterBar
                activeCount={[status, userId, from, to].filter(Boolean).length + (onlyVariance ? 1 : 0)}
                onClear={() => { setStatus(''); setUserId(''); setFrom(''); setTo(''); setOnlyVariance(false); }}
            >
                <Field label={t('billing.folios.status')}>
                    <select className="select sm:w-44" value={status} onChange={(e) => setStatus(e.target.value)}>
                        <option value="">{t('billing.cashier.all_shifts')}</option>
                        <option value="open">{t('billing.status.open')}</option>
                        <option value="closed">{t('billing.status.closed')}</option>
                    </select>
                </Field>
                <Field label={t('billing.cashier.cashier')}>
                    <select className="select sm:w-48" value={userId} onChange={(e) => setUserId(e.target.value)}>
                        <option value="">{t('billing.cashier.all_cashiers')}</option>
                        {Object.entries(cashiers).map(([id, name]) => <option key={id} value={id}>{name}</option>)}
                    </select>
                </Field>
                <Field label={t('billing.cashier.from')}><input type="date" className="input sm:w-40" value={from} onChange={(e) => setFrom(e.target.value)} /></Field>
                <Field label={t('billing.cashier.to')}><input type="date" className="input sm:w-40" value={to} onChange={(e) => setTo(e.target.value)} /></Field>
                <label className="flex min-h-11 items-center gap-2 text-sm text-slate-700">
                    <input type="checkbox" checked={onlyVariance} onChange={(e) => setOnlyVariance(e.target.checked)} /> {t('billing.cashier.only_variance')}
                </label>
            </FilterBar>

            {shifts.error && <div className="mb-4 rounded-lg text-sm px-4 py-3 bg-red-50 text-red-700">{shifts.error.message}</div>}
            <ExportButtons report="cashier-shifts" params={{ status, user_id: userId, from, to, per_page: 200 }} className="mb-3" />

            <div className="card overflow-x-auto">
                <table className="table-auto">
                    <thead><tr><th></th><th>{t('billing.cashier.shift')}</th><th>{t('billing.cashier.cashier')}</th><th>{t('billing.cashier.station')}</th><th>{t('billing.cashier.business_date')}</th><th>{t('billing.cashier.opened')}</th><th>{t('billing.cashier.closed')}</th><th className="text-right">{t('billing.cashier.float')}</th><th className="text-right">{t('billing.cashier.variance')}</th></tr></thead>
                    <tbody>
                        {shifts.loading && !shifts.data && <tr><td colSpan={9} className="py-10"><Loader2 className="w-5 h-5 mx-auto animate-spin text-slate-500" /></td></tr>}
                        {shifts.data && rows.length === 0 && <tr><td colSpan={9}><EmptyState title={t('billing.cashier.no_shifts')} description={t('gapf.cashier.empty_hint')} /></td></tr>}
                        {rows.map((s) => {
                            const v = s.variance ?? 0;
                            const methods = Array.from(new Set([...Object.keys(s.expected ?? {}), ...Object.keys(s.counted ?? {})]));
                            return [
                                <tr key={s.id} className={cn('cursor-pointer hover:bg-slate-50', s.status === 'closed' && v !== 0 && 'bg-red-50/50')} onClick={() => setExpanded(expanded === s.id ? null : s.id)}>
                                    <td>{expanded === s.id ? <ChevronDown className="w-4 h-4 text-slate-500" /> : <ChevronRight className="w-4 h-4 text-slate-500" />}</td>
                                    <td className="text-sm">#{s.id} <span className={cn('ml-1 text-xs px-2 py-0.5 rounded', s.status === 'open' ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500')}>{statusLabel(s.status)}</span></td>
                                    <td className="text-sm whitespace-nowrap">{s.user_name ?? t('billing.cashier.user_n', { id: s.user_id })}</td>
                                    <td className="text-sm">{s.station ?? '—'}</td>
                                    <td className="text-sm">{s.business_date ? formatDate(s.business_date) : '—'}</td>
                                    <td className="text-sm whitespace-nowrap">{formatDateTime(s.opened_at)}</td>
                                    <td className="text-sm whitespace-nowrap">{s.closed_at ? formatDateTime(s.closed_at) : '—'}</td>
                                    <td className="text-right text-sm">{formatCurrency(s.opening_float)}</td>
                                    <td className={cn('text-right text-sm font-semibold', v < 0 ? 'text-red-600' : v > 0 ? 'text-amber-600' : 'text-slate-500')}>
                                        {s.status === 'closed' ? formatCurrency(v) : '—'}
                                    </td>
                                </tr>,
                                expanded === s.id && (
                                    <tr key={`${s.id}-d`}>
                                        <td />
                                        <td colSpan={8} className="pb-4">
                                            <div className="mb-2" onClick={(e) => e.stopPropagation()}><PrintPdfButton path={`shift-report/${s.id}`} label={t('documents3.print_z')} /></div>
                                            {methods.length === 0 ? <p className="text-sm text-slate-500">{s.status === 'open' ? t('billing.cashier.no_takings_open') : t('billing.cashier.no_takings')}</p> : (
                                                <div className="table-wrap"><table className="table-auto">
                                                    <thead><tr className="text-left text-xs text-slate-500"><th className="py-1">{t('billing.cashier.method')}</th><th className="py-1 text-right">{t('billing.cashier.expected')}</th><th className="py-1 text-right">{t('billing.cashier.counted')}</th><th className="py-1 text-right">{t('billing.cashier.variance')}</th></tr></thead>
                                                    <tbody>
                                                        {methods.map((m) => {
                                                            const exp = s.expected?.[m] ?? 0;
                                                            const cnt = s.counted?.[m];
                                                            const d = cnt === undefined ? null : cnt - exp;
                                                            return (
                                                                <tr key={m} className={cn(d && 'bg-red-50')}>
                                                                    <td className="py-1 font-mono text-xs">{m}</td>
                                                                    <td className="py-1 text-right">{formatCurrency(exp)}</td>
                                                                    <td className="py-1 text-right">{cnt === undefined ? '—' : formatCurrency(cnt)}</td>
                                                                    <td className={cn('py-1 text-right font-medium', d === null ? 'text-slate-500' : d < 0 ? 'text-red-600' : d > 0 ? 'text-amber-600' : 'text-emerald-600')}>
                                                                        {d === null ? '—' : formatCurrency(d)}
                                                                    </td>
                                                                </tr>
                                                            );
                                                        })}
                                                    </tbody>
                                                </table></div>
                                            )}
                                        </td>
                                    </tr>
                                ),
                            ];
                        })}
                    </tbody>
                </table>
            </div>
        </AppLayout>
    );
}
