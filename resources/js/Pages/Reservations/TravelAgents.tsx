import AppLayout from '@/Layouts/AppLayout';
import { Head, Link } from '@inertiajs/react';
import { Loader2 } from 'lucide-react';
import { Fragment, useState } from 'react';
import { api, ApiError, useApi } from '@/lib/api';
import { cn, formatCurrency, formatDate } from '@/lib/utils';
import { useT } from '@/lib/i18n';

interface Agent { id: number; name: string; iata_number: string | null; commission_percent: string | number; email: string | null; phone: string | null; active: boolean }
interface Line { reservation_id: number; number: string; guest: string; arrival: string; departure: string; status: string; revenue: number; commission: number }
interface Row { agent_id: number; name: string; iata_number: string | null; commission_percent: number; bookings: number; revenue: number; commission: number; lines: Line[] }
interface Report { data: Row[]; total_commission: number }

const iso = (d: Date) => d.toISOString().slice(0, 10);
const firstOfMonth = () => { const d = new Date(); return iso(new Date(Date.UTC(d.getFullYear(), d.getMonth(), 1))); };
const blank = { id: 0, name: '', iata_number: '', commission_percent: '10', email: '', phone: '' };

export default function TravelAgents() {
    const t = useT();
    const agents = useApi<{ data: Agent[] }>('travel-agents');
    const [form, setForm] = useState(blank);
    const [message, setMessage] = useState<{ ok: boolean; text: string } | null>(null);
    const [from, setFrom] = useState(firstOfMonth());
    const [to, setTo] = useState(iso(new Date()));
    const report = useApi<Report>(from && to && to >= from ? `travel-agents/commissions?from=${from}&to=${to}` : null);
    const [open, setOpen] = useState<number | null>(null);

    async function save() {
        setMessage(null);
        try {
            const body = JSON.stringify({ name: form.name, iata_number: form.iata_number || null, commission_percent: +form.commission_percent, email: form.email || null, phone: form.phone || null });
            await api(form.id ? `travel-agents/${form.id}` : 'travel-agents', { method: form.id ? 'PATCH' : 'POST', body });
            setMessage({ ok: true, text: form.id ? t('res.agents.updated') : t('res.agents.added') });
            setForm(blank);
            agents.reload();
            report.reload();
        } catch (e) {
            setMessage({ ok: false, text: e instanceof ApiError ? e.message : String(e) });
        }
    }

    return (
        <AppLayout>
            <Head title={t('res.agents.title')} />
            <div className="mx-auto max-w-5xl space-y-4 p-4">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <h1 className="text-xl font-semibold">{t('res.agents.title')}</h1>
                    <Link href="/reservations" className="btn-secondary">{t('res.title')}</Link>
                </div>

                <section className="card space-y-3 p-4">
                    <h2 className="font-medium">{form.id ? t('res.agents.edit') : t('res.agents.new')}</h2>
                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                        <label className="label">{t('res.agents.name')}<input className="input w-full" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} /></label>
                        <label className="label">{t('res.agents.iata_number')}<input className="input w-full" value={form.iata_number} onChange={(e) => setForm({ ...form, iata_number: e.target.value })} /></label>
                        <label className="label">{t('res.agents.commission_pct')}<input className="input w-full" type="number" min={0} max={50} step="0.5" value={form.commission_percent} onChange={(e) => setForm({ ...form, commission_percent: e.target.value })} /></label>
                        <label className="label">{t('res.email')}<input className="input w-full" type="email" value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} /></label>
                        <label className="label">{t('res.phone')}<input className="input w-full" value={form.phone} onChange={(e) => setForm({ ...form, phone: e.target.value })} /></label>
                    </div>
                    <div className="flex gap-2">
                        <button type="button" className="btn-primary" disabled={!form.name} onClick={save}>{form.id ? t('common.save') : t('res.agents.add')}</button>
                        {form.id > 0 && <button type="button" className="btn-secondary" onClick={() => setForm(blank)}>{t('common.cancel')}</button>}
                    </div>
                </section>

                {message && <div className={cn('rounded-md border p-3 text-sm', message.ok ? 'border-emerald-300 bg-emerald-50 text-emerald-800' : 'border-red-300 bg-red-50 text-red-800')}>{message.text}</div>}

                <section className="card overflow-x-auto p-4">
                    <h2 className="mb-2 font-medium">{t('res.agents.list')}</h2>
                    {agents.loading && !agents.data ? <Loader2 className="h-5 w-5 animate-spin" /> : (
                        <table className="table-auto w-full text-sm">
                            <thead><tr className="text-left text-gray-500"><th>{t('res.agents.name')}</th><th>{t('res.agents.iata')}</th><th className="text-right">{t('res.agents.commission')}</th><th /></tr></thead>
                            <tbody>
                                {agents.data?.data.map((a) => (
                                    <tr key={a.id} className="border-t border-gray-100">
                                        <td className="py-1">{a.name}</td>
                                        <td>{a.iata_number ?? '—'}</td>
                                        <td className="text-right">{Number(a.commission_percent)}%</td>
                                        <td className="text-right">
                                            <button type="button" className="btn-secondary" onClick={() => setForm({ id: a.id, name: a.name, iata_number: a.iata_number ?? '', commission_percent: String(a.commission_percent), email: a.email ?? '', phone: a.phone ?? '' })}>{t('common.edit')}</button>
                                        </td>
                                    </tr>
                                ))}
                                {agents.data?.data.length === 0 && <tr><td colSpan={4} className="py-2 text-gray-500">{t('res.agents.empty')}</td></tr>}
                            </tbody>
                        </table>
                    )}
                </section>

                <section className="card space-y-3 p-4">
                    <div className="flex flex-wrap items-end justify-between gap-2">
                        <h2 className="font-medium">{t('res.agents.report')}</h2>
                        <div className="flex flex-wrap gap-2">
                            <label className="label">{t('res.seasons.from')}<input className="input" type="date" value={from} onChange={(e) => setFrom(e.target.value)} /></label>
                            <label className="label">{t('res.agents.to')}<input className="input" type="date" value={to} min={from} onChange={(e) => setTo(e.target.value)} /></label>
                        </div>
                    </div>
                    {report.error && <p className="text-sm text-red-700">{report.error.message}</p>}
                    {report.data && (
                        <div className="overflow-x-auto">
                            <table className="table-auto w-full text-sm">
                                <thead><tr className="text-left text-gray-500"><th>{t('res.agents.agent')}</th><th className="text-right">{t('res.agents.bookings')}</th><th className="text-right">{t('res.agents.revenue')}</th><th className="text-right">{t('res.agents.commission')}</th></tr></thead>
                                <tbody>
                                    {report.data.data.map((r) => (
                                        <Fragment key={r.agent_id}>
                                            <tr className="cursor-pointer border-t border-gray-100 hover:bg-gray-50" onClick={() => setOpen(open === r.agent_id ? null : r.agent_id)}>
                                                <td className="py-1">{r.name} <span className="text-xs text-gray-500">{r.iata_number ?? ''} · {r.commission_percent}%</span></td>
                                                <td className="text-right">{r.bookings}</td>
                                                <td className="text-right">{formatCurrency(r.revenue)}</td>
                                                <td className="text-right font-medium">{formatCurrency(r.commission)}</td>
                                            </tr>
                                            {open === r.agent_id && r.lines.map((l) => (
                                                <tr key={`${r.agent_id}-${l.reservation_id}`} className="bg-gray-50 text-gray-600">
                                                    <td className="py-1 pl-4"><Link className="underline" href={`/reservations/${l.reservation_id}`}>{l.number}</Link> {l.guest} · {formatDate(l.arrival)}</td>
                                                    <td className="text-right">{t(`res.status.${l.status}`)}</td>
                                                    <td className="text-right">{formatCurrency(l.revenue)}</td>
                                                    <td className="text-right">{formatCurrency(l.commission)}</td>
                                                </tr>
                                            ))}
                                        </Fragment>
                                    ))}
                                    {report.data.data.length === 0 && <tr><td colSpan={4} className="py-2 text-gray-500">{t('res.agents.report_empty')}</td></tr>}
                                </tbody>
                                <tfoot><tr className="border-t border-gray-200 font-semibold"><td colSpan={3} className="py-1">{t('res.agents.total_due')}</td><td className="text-right">{formatCurrency(report.data.total_commission)}</td></tr></tfoot>
                            </table>
                        </div>
                    )}
                </section>
            </div>
        </AppLayout>
    );
}
