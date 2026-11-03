import AppLayout from '@/Layouts/AppLayout';
import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, Loader2, Search, Star } from 'lucide-react';
import { useEffect, useState } from 'react';
import { useApi } from '@/lib/api';
import { cn, formatCurrency, formatDate } from '@/lib/utils';
import { useT } from '@/lib/i18n';

interface Row {
    id: number;
    number: string;
    guest: { name: string; vip: boolean; phone: string | null } | null;
    room: string | null;
    room_type: string | null;
    company: string | null;
    arrival: string;
    departure: string;
    adults: number;
    balance: number;
}

export default function InHouse() {
    const t = useT();
    const [q, setQ] = useState('');
    const [term, setTerm] = useState('');
    useEffect(() => {
        const timer = setTimeout(() => setTerm(q.trim()), 300);
        return () => clearTimeout(timer);
    }, [q]);
    const list = useApi<Row[]>(`front-desk/in-house${term ? `?q=${encodeURIComponent(term)}` : ''}`);
    const rows = list.data ?? [];
    const total = rows.reduce((s, r) => s + r.balance, 0);

    return (
        <AppLayout title={t('fo.inhouse.title')} subtitle={list.data ? t('fo.inhouse.summary', { count: rows.length, amount: formatCurrency(total) }) : t('fo.inhouse.subtitle')}
            actions={<Link href="/front-desk" className="btn-secondary"><ArrowLeft className="w-4 h-4" /> {t('fo.inhouse.back')}</Link>}>
            <Head title={t('fo.inhouse.title')} />

            <div className="relative mb-4 max-w-md">
                <Search className="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
                <input className="input pl-9" placeholder={t('fo.inhouse.search')} value={q} onChange={(e) => setQ(e.target.value)} />
            </div>

            {list.error && <div className="mb-4 rounded-lg text-sm px-4 py-3 bg-red-50 text-red-700">{list.error.message}</div>}

            <div className="card overflow-x-auto">
                <table className="table-auto w-full">
                    <thead><tr><th>{t('fo.inhouse.room')}</th><th>{t('fo.inhouse.guest')}</th><th>{t('fo.inhouse.company')}</th><th>{t('fo.inhouse.arrival')}</th><th>{t('fo.inhouse.departure')}</th><th className="text-right">{t('fo.inhouse.balance')}</th></tr></thead>
                    <tbody>
                        {list.loading && !list.data && <tr><td colSpan={6} className="py-10"><Loader2 className="w-5 h-5 mx-auto animate-spin text-slate-400" /></td></tr>}
                        {list.data && rows.length === 0 && <tr><td colSpan={6} className="text-center py-10 text-slate-400 text-sm">{term ? t('fo.inhouse.no_match') : t('fo.inhouse.empty')}</td></tr>}
                        {rows.map((r) => (
                            <tr key={r.id} className="hover:bg-slate-50">
                                <td className="text-sm font-semibold whitespace-nowrap">{r.room ?? '—'} <span className="text-xs font-normal text-slate-500">{r.room_type}</span></td>
                                <td className="text-sm">
                                    <Link href={`/reservations/${r.id}`} className="text-blue-700 hover:underline">{r.guest?.name ?? '—'}</Link>
                                    {r.guest?.vip && <span className="badge-yellow ml-2 inline-flex items-center gap-1"><Star className="w-3 h-3" /> VIP</span>}
                                    <div className="text-xs text-slate-500">{r.number}</div>
                                </td>
                                <td className="text-sm">{r.company ?? '—'}</td>
                                <td className="text-sm whitespace-nowrap">{formatDate(r.arrival)}</td>
                                <td className="text-sm whitespace-nowrap">{formatDate(r.departure)}</td>
                                <td className={cn('text-right text-sm font-semibold whitespace-nowrap', r.balance > 0 ? 'text-red-600' : 'text-slate-600')}>{formatCurrency(r.balance)}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </AppLayout>
    );
}
