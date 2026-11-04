import AppLayout from '@/Layouts/AppLayout';
import { Head } from '@inertiajs/react';
import { Loader2, Pencil, Plus, Send, Trash2, X } from 'lucide-react';
import { useState } from 'react';
import EmptyState from '@/Components/ui/EmptyState';
import QueryStatus from '@/Components/ui/QueryStatus';
import { api, ApiError, useApi } from '@/lib/api';
import { formatDateTime } from '@/lib/utils';
import { useT } from '@/lib/i18n';

interface Schedule {
    id: number;
    name: string;
    report: string;
    params: Record<string, string>;
    format: 'xlsx' | 'csv' | 'pdf';
    recipients: string[];
    frequency: string;
    active: boolean;
    next_run_at: string | null;
    last_run_at: string | null;
    last_status: string | null;
    last_error: string | null;
}

interface ReportKey { key: string; title: string }

interface Form {
    id: number | null;
    name: string;
    report: string;
    params: string;
    format: 'xlsx' | 'csv' | 'pdf';
    recipients: string;
    frequency: string;
    active: boolean;
}

const PRESETS: { label: string; cron: string }[] = [
    { label: 'docs.sched.preset.daily', cron: '0 7 * * *' },
    { label: 'docs.sched.preset.monday', cron: '0 7 * * 1' },
    { label: 'docs.sched.preset.monthly', cron: '0 7 1 * *' },
];

const EMPTY: Form = { id: null, name: '', report: 'managers-report', params: '', format: 'pdf', recipients: '', frequency: '0 7 * * *', active: true };

/** "from=2026-01-01&to=2026-01-31" <-> {from, to} */
function parseParams(s: string): Record<string, string> {
    return Object.fromEntries(new URLSearchParams(s.trim()).entries());
}

export default function ScheduledReports() {
    const t = useT();
    const list = useApi<Schedule[]>('scheduled-reports');
    const reports = useApi<ReportKey[]>('exports');
    const [form, setForm] = useState<Form | null>(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [confirmDelete, setConfirmDelete] = useState<number | null>(null);
    const [message, setMessage] = useState<string | null>(null);

    const title = (key: string) => reports.data?.find((r) => r.key === key)?.title ?? key;

    async function save() {
        if (!form) return;
        setBusy(true);
        setError(null);
        try {
            const body = JSON.stringify({
                name: form.name,
                report: form.report,
                params: parseParams(form.params),
                format: form.format,
                recipients: form.recipients.split(/[\s,;]+/).filter(Boolean),
                frequency: form.frequency,
                active: form.active,
            });
            await api(form.id ? `scheduled-reports/${form.id}` : 'scheduled-reports', { method: form.id ? 'PUT' : 'POST', body });
            setForm(null);
            list.reload();
        } catch (e) {
            setError(e instanceof ApiError ? e.message : String(e));
        } finally {
            setBusy(false);
        }
    }

    async function act(path: string, method: string, done: string) {
        setBusy(true);
        setMessage(null);
        try {
            const s = await api<Schedule | undefined>(path, { method });
            setMessage(s?.last_status === 'failed' ? t('docs.sched.failed', { error: String(s?.last_error) }) : done);
            list.reload();
        } catch (e) {
            setMessage(e instanceof ApiError ? e.message : String(e));
        } finally {
            setBusy(false);
            setConfirmDelete(null);
        }
    }

    return (
        <AppLayout
            title={t('docs.sched.title')}
            subtitle={t('docs.sched.subtitle')}
            actions={<button className="btn-primary" onClick={() => setForm({ ...EMPTY })}><Plus className="w-4 h-4" /> {t('docs.sched.new')}</button>}
        >
            <Head title={t('docs.sched.title')} />

            {message && <div className="card mb-4 text-sm">{message}</div>}

            {form && (
                <div className="card mb-4 space-y-3">
                    <div className="flex items-center justify-between">
                        <h2 className="text-base font-semibold">{form.id ? t('docs.sched.edit') : t('docs.sched.new')}</h2>
                        <button className="btn-secondary" onClick={() => setForm(null)} aria-label={t('docs.close')}><X className="w-4 h-4" /></button>
                    </div>
                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 [&>*]:min-w-0">
                        <div>
                            <label className="label">{t('docs.sched.name')}</label>
                            <input className="input w-full" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
                        </div>
                        <div>
                            <label className="label">{t('docs.sched.report')}</label>
                            <select className="select w-full" value={form.report} onChange={(e) => setForm({ ...form, report: e.target.value })}>
                                {(reports.data ?? []).map((r) => <option key={r.key} value={r.key}>{r.title}</option>)}
                            </select>
                        </div>
                        <div>
                            <label className="label">{t('docs.sched.params')}</label>
                            <input className="input w-full" placeholder="from=2026-01-01&to=2026-01-31" value={form.params} onChange={(e) => setForm({ ...form, params: e.target.value })} />
                        </div>
                        <div>
                            <label className="label">{t('docs.sched.format')}</label>
                            <select className="select w-full" value={form.format} onChange={(e) => setForm({ ...form, format: e.target.value as Form['format'] })}>
                                <option value="pdf">PDF</option>
                                <option value="xlsx">{t('docs.sched.excel')}</option>
                                <option value="csv">CSV</option>
                            </select>
                        </div>
                        <div className="sm:col-span-2">
                            <label className="label">{t('docs.sched.recipients')}</label>
                            <input className="input w-full" value={form.recipients} onChange={(e) => setForm({ ...form, recipients: e.target.value })} />
                        </div>
                        <div>
                            <label className="label">{t('docs.sched.frequency')}</label>
                            <input className="input w-full font-mono" value={form.frequency} onChange={(e) => setForm({ ...form, frequency: e.target.value })} />
                            <div className="flex flex-wrap gap-2 mt-2">
                                {PRESETS.map((p) => (
                                    <button key={p.cron} type="button" className="btn-secondary" onClick={() => setForm({ ...form, frequency: p.cron })}>{t(p.label)}</button>
                                ))}
                            </div>
                        </div>
                        <div className="flex items-center gap-2">
                            <input id="active" type="checkbox" className="h-5 w-5 shrink-0" checked={form.active} onChange={(e) => setForm({ ...form, active: e.target.checked })} />
                            <label htmlFor="active" className="text-sm">{t('docs.sched.active')}</label>
                        </div>
                    </div>
                    {error && <p className="text-sm text-red-600">{error}</p>}
                    <div className="flex gap-2">
                        <button className="btn-primary" disabled={busy} onClick={save}>{busy && <Loader2 className="w-4 h-4 animate-spin" />} {t('common.save')}</button>
                        <button className="btn-secondary" onClick={() => setForm(null)}>{t('common.cancel')}</button>
                    </div>
                </div>
            )}

            <div className="card overflow-x-auto">
                {!list.data ? (
                    <QueryStatus query={list} variant="table" />
                ) : !list.data.length ? (
                    <EmptyState title={t('docs.sched.empty')} description={t('states3.empty.schedules_docs.text')} action={<button type="button" className="btn-primary" onClick={() => setForm({ ...EMPTY })}>{t('docs.sched.new')}</button>} />
                ) : (
                    <table className="table-auto w-full text-sm">
                        <thead>
                            <tr><th>{t('docs.sched.name')}</th><th>{t('docs.sched.report')}</th><th>{t('docs.sched.format')}</th><th>{t('docs.sched.frequency_col')}</th><th>{t('docs.sched.next_run')}</th><th>{t('docs.sched.last_run')}</th><th></th></tr>
                        </thead>
                        <tbody>
                            {list.data.map((s) => (
                                <tr key={s.id}>
                                    <td>
                                        <div className="font-medium">{s.name}</div>
                                        <div className="text-xs text-slate-500">{s.recipients.join(', ')}</div>
                                    </td>
                                    <td>{title(s.report)}</td>
                                    <td><span className="badge-slate">{s.format.toUpperCase()}</span></td>
                                    <td className="font-mono">{s.frequency}{!s.active && <span className="badge-yellow ml-2">{t('docs.sched.paused')}</span>}</td>
                                    <td>{s.next_run_at ? formatDateTime(s.next_run_at) : '—'}</td>
                                    <td>
                                        {s.last_run_at ? formatDateTime(s.last_run_at) : '—'}{' '}
                                        {s.last_status && <span className={s.last_status === 'sent' ? 'badge-green' : 'badge-red'} title={s.last_error ?? ''}>{s.last_status}</span>}
                                    </td>
                                    <td className="whitespace-nowrap text-right">
                                        {confirmDelete === s.id ? (
                                            <span className="inline-flex gap-2">
                                                <button className="btn-secondary" disabled={busy} onClick={() => act(`scheduled-reports/${s.id}`, 'DELETE', t('docs.sched.deleted'))}>{t('docs.sched.confirm_delete')}</button>
                                                <button className="btn-secondary" onClick={() => setConfirmDelete(null)}>{t('common.cancel')}</button>
                                            </span>
                                        ) : (
                                            <span className="inline-flex gap-2">
                                                <button className="btn-secondary btn-icon" title={t('docs.sched.send_now')} disabled={busy} onClick={() => act(`scheduled-reports/${s.id}/run`, 'POST', t('docs.sched.sent'))} aria-label={t('docs.sched.send_now')}><Send className="w-4 h-4" /></button>
                                                <button className="btn-secondary btn-icon" title={t('common.edit')} onClick={() => setForm({
                                                    id: s.id, name: s.name, report: s.report, params: new URLSearchParams(s.params ?? {}).toString(),
                                                    format: s.format, recipients: s.recipients.join(', '), frequency: s.frequency, active: s.active,
                                                })} aria-label={t('common.edit')}><Pencil className="w-4 h-4" /></button>
                                                <button className="btn-secondary btn-icon" title={t('common.delete')} onClick={() => setConfirmDelete(s.id)} aria-label={t('common.delete')}><Trash2 className="w-4 h-4" /></button>
                                            </span>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
            </div>
        </AppLayout>
    );
}
