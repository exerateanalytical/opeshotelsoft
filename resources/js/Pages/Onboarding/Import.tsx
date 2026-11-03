import AppLayout from '@/Layouts/AppLayout';
import { Head, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { api, ApiError, useApi } from '@/lib/api';
import { useT } from '@/lib/i18n';
import { OnboardingTabs, upload } from './shared';

const KINDS = ['rooms', 'guests', 'staff', 'opening_balances', 'stock_items'] as const;
const PARTS = ['room_types', 'rooms', 'rate_plans', 'outlets', 'tax_codes', 'charge_codes', 'payment_methods', 'templates', 'branding'];

interface Batch { id: number; kind: string; filename: string | null; dry_run: boolean; status: string; rows_total: number; rows_ok: number; rows_failed: number; report: { row: number; ok: boolean; errors: string[] }[]; created_at: string }

export default function Import() {
    const t = useT();
    const [kind, setKind] = useState<(typeof KINDS)[number]>('rooms');
    const [file, setFile] = useState<File | null>(null);
    const [date, setDate] = useState('');
    const [busy, setBusy] = useState(false);
    const [result, setResult] = useState<Batch | null>(null);
    const [error, setError] = useState<string | null>(null);
    const history = useApi<Batch[]>('onboarding/imports');

    async function send(dryRun: boolean) {
        if (!file) return;
        setBusy(true);
        setError(null);
        const form = new FormData();
        form.append('file', file);
        form.append('dry_run', dryRun ? '1' : '0');
        if (kind === 'opening_balances' && date) form.append('date', date);
        const res = await upload<Batch & { detail?: string; message?: string }>(`onboarding/imports/${kind}`, form);
        if (res.body && 'status' in res.body && typeof res.body.status === 'string') setResult(res.body);
        else setError(res.body?.detail ?? res.body?.message ?? t('onboarding.error'));
        setBusy(false);
        history.reload();
    }

    return (
        <AppLayout title={t('onboarding.import.title')} subtitle={t('onboarding.import.subtitle')}>
            <Head title={t('onboarding.import.title')} />
            <OnboardingTabs active="/onboarding/import" />
            <div className="card p-4 mb-4 space-y-3">
                <div className="grid gap-3 sm:grid-cols-3 items-end">
                    <label className="block">
                        <span className="label">{t('onboarding.import.kind')}</span>
                        <select className="input" value={kind} onChange={(e) => { setKind(e.target.value as typeof kind); setResult(null); }}>
                            {KINDS.map((k) => <option key={k} value={k}>{t(`onboarding.import.kind.${k}`)}</option>)}
                        </select>
                    </label>
                    <label className="block">
                        <span className="label">{t('onboarding.import.file')}</span>
                        <input className="input" type="file" accept=".csv,.xlsx,.txt" onChange={(e) => setFile(e.target.files?.[0] ?? null)} />
                    </label>
                    {kind === 'opening_balances' && (
                        <label className="block">
                            <span className="label">{t('onboarding.import.date')}</span>
                            <input className="input" type="date" value={date} onChange={(e) => setDate(e.target.value)} />
                        </label>
                    )}
                </div>
                <p className="text-sm text-slate-500">{t(`onboarding.import.help.${kind}`)} <a className="underline" href={`/api/v1/onboarding/imports/${kind}/template`}>{t('onboarding.import.template')}</a></p>
                <div className="flex gap-2">
                    <button type="button" className="btn-secondary" disabled={busy || !file} onClick={() => send(true)}>{t('onboarding.import.dry_run')}</button>
                    <button type="button" className="btn-primary" disabled={busy || !file} onClick={() => send(false)}>{t('onboarding.import.run')}</button>
                </div>
                {error && <p className="text-sm text-red-700">{error}</p>}
                {result && (
                    <div className="text-sm">
                        <p className={result.status === 'rejected' ? 'text-red-700' : 'text-emerald-700'}>
                            {t(`onboarding.import.status.${result.status}`)} — {t('onboarding.import.summary', { ok: result.rows_ok, failed: result.rows_failed, total: result.rows_total })}
                        </p>
                        {result.report.some((r) => !r.ok) && (
                            <div className="overflow-x-auto"><table className="mt-2 w-full">
                                <thead><tr className="text-left text-slate-500"><th className="w-20 py-1">{t('onboarding.import.row')}</th><th>{t('onboarding.import.errors')}</th></tr></thead>
                                <tbody>
                                    {result.report.filter((r) => !r.ok).map((r, i) => (
                                        <tr key={i} className="border-t border-slate-100"><td className="py-1">{r.row || '—'}</td><td className="text-red-700">{r.errors.join(' · ')}</td></tr>
                                    ))}
                                </tbody>
                            </table></div>
                        )}
                    </div>
                )}
            </div>

            <CopySetup />

            <div className="card p-4">
                <h2 className="mb-2 font-semibold text-slate-900">{t('onboarding.import.history')}</h2>
                <div className="overflow-x-auto"><table className="w-full text-sm">
                    <tbody>
                        {(history.data ?? []).map((b) => (
                            <tr key={b.id} className="border-t border-slate-100">
                                <td className="py-1">{new Date(b.created_at).toLocaleString()}</td>
                                <td>{t(`onboarding.import.kind.${b.kind}`)}</td>
                                <td>{b.filename}</td>
                                <td>{t(`onboarding.import.status.${b.status}`)}</td>
                                <td className="text-right">{b.rows_ok}/{b.rows_total}</td>
                            </tr>
                        ))}
                    </tbody>
                </table></div>
            </div>
        </AppLayout>
    );
}

function CopySetup() {
    const t = useT();
    const current = usePage<{ property?: { id: number } | null }>().props.property?.id;
    const hotels = useApi<{ data: { id: number; name: string }[] }>('hotels');
    const [source, setSource] = useState('');
    const [parts, setParts] = useState<string[]>(['room_types', 'rooms', 'rate_plans', 'outlets', 'templates', 'branding']);
    const [msg, setMsg] = useState<{ ok: boolean; text: string } | null>(null);
    const [busy, setBusy] = useState(false);

    async function copy() {
        setBusy(true);
        setMsg(null);
        try {
            const r = await api<{ result: Record<string, { copied: number; skipped: number }> }>('onboarding/copy', { method: 'POST', body: JSON.stringify({ source_hotel_id: Number(source), parts }) });
            setMsg({ ok: true, text: Object.entries(r.result).map(([k, v]) => `${t(`onboarding.part.${k}`)}: ${v.copied} / ${v.skipped}`).join(' · ') });
        } catch (e) {
            setMsg({ ok: false, text: e instanceof ApiError ? e.message : t('onboarding.error') });
        } finally {
            setBusy(false);
        }
    }

    return (
        <div className="card p-4 mb-4 space-y-3">
            <h2 className="font-semibold text-slate-900">{t('onboarding.copy.title')}</h2>
            <p className="text-sm text-slate-500">{t('onboarding.copy.help')}</p>
            <select className="input max-w-sm" value={source} onChange={(e) => setSource(e.target.value)}>
                <option value="">{t('onboarding.copy.source')}</option>
                {(hotels.data?.data ?? []).filter((h) => h.id !== current).map((h) => <option key={h.id} value={h.id}>{h.name}</option>)}
            </select>
            <div className="flex flex-wrap gap-3 text-sm">
                {PARTS.map((p) => (
                    <label key={p} className="flex items-center gap-1">
                        <input type="checkbox" checked={parts.includes(p)} onChange={(e) => setParts((x) => (e.target.checked ? [...x, p] : x.filter((y) => y !== p)))} />
                        {t(`onboarding.part.${p}`)}
                    </label>
                ))}
            </div>
            <button type="button" className="btn-primary" disabled={busy || !source || parts.length === 0} onClick={copy}>{t('onboarding.copy.run')}</button>
            {msg && <p className={`text-sm ${msg.ok ? 'text-emerald-700' : 'text-red-700'}`}>{msg.text}</p>}
        </div>
    );
}
