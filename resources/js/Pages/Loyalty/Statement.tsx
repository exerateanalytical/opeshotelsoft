import AppLayout from '@/Layouts/AppLayout';
import { Head } from '@inertiajs/react';
import { FileDown, Loader2, Mail } from 'lucide-react';
import { useState } from 'react';
import { api, useApi } from '@/lib/api';
import { formatDate } from '@/lib/utils';
import { useT } from '@/lib/i18n';
import { errorText, openPdf } from './lib';
import Alert from '@/Components/ui/Alert';

interface Statement {
    member: { id: number; number: string; name: string; tier: string | null; email: string | null };
    from: string;
    to: string;
    opening: number;
    earned: number;
    spent: number;
    closing: number;
    expiring: number;
    lines: { id: number; date: string; kind: string; description: string; points: number; balance: number }[];
}

const iso = (d: Date) => d.toISOString().slice(0, 10);

export default function LoyaltyStatement({ memberId }: { memberId: number }) {
    const t = useT();
    const [from, setFrom] = useState(() => { const d = new Date(); d.setMonth(d.getMonth() - 3); return iso(d); });
    const [to, setTo] = useState(() => iso(new Date()));
    const qs = `from=${from}&to=${to}`;
    const s = useApi<Statement>(`loyalty/members/${memberId}/statement?${qs}`);
    const [msg, setMsg] = useState<{ kind: 'ok' | 'error'; text: string } | null>(null);
    const [sending, setSending] = useState(false);

    async function email() {
        setSending(true); setMsg(null);
        try {
            await api(`loyalty/members/${memberId}/statement/email?${qs}`, { method: 'POST', body: '{}' });
            setMsg({ kind: 'ok', text: t('loyalty.stmt.sent') });
        } catch (e) { setMsg({ kind: 'error', text: errorText(e) }); } finally { setSending(false); }
    }

    const d = s.data;
    const n = (v: number) => v.toLocaleString();

    return (
        <AppLayout title={t('loyalty.stmt.title')} subtitle={d ? `${d.member.name} · ${d.member.number}` : ''}
            actions={
                <div className="flex gap-2">
                    <button className="btn-secondary inline-flex items-center gap-2" onClick={() => openPdf(`loyalty/members/${memberId}/statement/pdf?${qs}`).catch((e) => setMsg({ kind: 'error', text: errorText(e) }))}><FileDown className="w-4 h-4" /> PDF</button>
                    <button className="btn-primary inline-flex items-center gap-2" disabled={sending || !d?.member.email} title={d?.member.email ?? t('loyalty.stmt.no_email')} onClick={email}><Mail className="w-4 h-4" /> {t('loyalty.stmt.email')}</button>
                </div>
            }>
            <Head title={t('loyalty.stmt.title')} />
            {msg && <Alert variant={msg.kind === 'error' ? 'danger' : 'success'} className="mb-4">{msg.text}</Alert>}
            <div className="flex flex-wrap gap-3 mb-4 items-end text-sm">
                <label>{t('loyalty.stmt.from')}<input type="date" className="input block" value={from} onChange={(e) => setFrom(e.target.value)} /></label>
                <label>{t('loyalty.stmt.to')}<input type="date" className="input block" value={to} onChange={(e) => setTo(e.target.value)} /></label>
            </div>
            {s.loading && !d && <Loader2 className="w-5 h-5 animate-spin text-slate-500" />}
            {s.error && <div className="text-sm text-red-600">{s.error.message}</div>}
            {d && (
                <>
                    <div className="grid grid-cols-2 sm:grid-cols-5 gap-2 mb-4 text-center">
                        {([['opening', d.opening], ['earned', d.earned], ['spent', d.spent], ['closing', d.closing], ['expiring', d.expiring]] as const).map(([k, v]) => (
                            <div key={k} className="card p-3"><div className="text-xs text-slate-500">{t(`loyalty.stmt.${k}`)}</div><div className="font-semibold">{n(v)}</div></div>
                        ))}
                    </div>
                    <div className="card table-wrap">
                        <table className="table-auto w-full text-sm">
                            <thead><tr className="text-left text-xs text-slate-500 border-b"><th className="p-2">{t('loyalty.stmt.date')}</th><th className="p-2">{t('loyalty.stmt.activity')}</th><th className="p-2 text-right">{t('loyalty.stmt.points')}</th><th className="p-2 text-right">{t('loyalty.stmt.balance')}</th></tr></thead>
                            <tbody>
                                {d.lines.map((l) => (
                                    <tr key={l.id} className="border-b last:border-0">
                                        <td className="p-2 whitespace-nowrap">{formatDate(l.date)}</td><td className="p-2">{l.description}</td>
                                        <td className={`p-2 text-right ${l.points < 0 ? 'text-red-600' : 'text-emerald-700'}`}>{l.points > 0 ? '+' : ''}{n(l.points)}</td>
                                        <td className="p-2 text-right">{n(l.balance)}</td>
                                    </tr>
                                ))}
                                {d.lines.length === 0 && <tr><td colSpan={4} className="p-4 text-center text-slate-500">{t('loyalty.stmt.empty')}</td></tr>}
                            </tbody>
                        </table>
                    </div>
                </>
            )}
        </AppLayout>
    );
}
