import AppLayout from '@/Layouts/AppLayout';
import { Head, Link, usePage } from '@inertiajs/react';
import { Loader2 } from 'lucide-react';
import { useState } from 'react';
import { api, ApiError, useApi } from '@/lib/api';
import { useT } from '@/lib/i18n';
import { AlertRow, fcfa, severityClass } from './shared';

const RULES = ['void_after_fire', 'comp_above', 'discount_above', 'room_move_no_charge', 'occupied_no_posting', 'cashier_variance',
    'bar_stock_gap', 'late_checkout_uncharged', 'rate_below_floor', 'reopened_check', 'after_hours_posting'];

export default function Alerts() {
    const t = useT();
    const perms = (usePage().props as { auth?: { user?: { permissions?: string[] } | null } }).auth?.user?.permissions ?? [];
    const canManage = perms.includes('insights.manage');
    const [status, setStatus] = useState('active');
    const [rule, setRule] = useState('');
    const [notes, setNotes] = useState<Record<number, string>>({});
    const [message, setMessage] = useState<{ ok: boolean; text: string } | null>(null);
    const params = new URLSearchParams({ status, ...(rule ? { rule } : {}) });
    const list = useApi<{ data: AlertRow[] }>(`insights/alerts?${params}`, [status, rule]);

    async function act(a: AlertRow, action: 'acknowledge' | 'resolve' | 'scan') {
        if (action === 'resolve' && !notes[a.id]?.trim()) {
            setMessage({ ok: false, text: t('insights.alerts.note_required') });
            return;
        }
        try {
            if (action === 'scan') {
                const r = await api<{ findings: number }>('insights/scan', { method: 'POST', body: '{}' });
                setMessage({ ok: true, text: t('insights.alerts.scanned', { count: r.findings }) });
            } else {
                await api(`insights/alerts/${a.id}/${action}`, { method: 'POST', body: JSON.stringify({ note: notes[a.id] || null }) });
                setMessage(null);
            }
            list.reload();
        } catch (e) {
            setMessage({ ok: false, text: e instanceof ApiError ? e.message : t('insights.error') });
        }
    }

    return (
        <AppLayout title={t('insights.alerts.title')} subtitle={t('insights.alerts.subtitle')}
            actions={canManage ? <button type="button" className="btn-secondary" onClick={() => act({} as AlertRow, 'scan')}>{t('insights.alerts.scan')}</button> : undefined}>
            <Head title={t('insights.alerts.title')} />
            <div className="mb-3 flex flex-wrap gap-2">
                <select className="input" value={status} onChange={(e) => setStatus(e.target.value)}>
                    {['active', 'open', 'acknowledged', 'resolved'].map((s) => <option key={s} value={s}>{t(`insights.alerts.status.${s}`)}</option>)}
                </select>
                <select className="input" value={rule} onChange={(e) => setRule(e.target.value)}>
                    <option value="">{t('insights.alerts.all_rules')}</option>
                    {RULES.map((r) => <option key={r} value={r}>{t(`insights.rule.${r}`)}</option>)}
                </select>
            </div>
            {message && <p className={`mb-3 text-sm ${message.ok ? 'text-emerald-700' : 'text-red-700'}`}>{message.text}</p>}
            {list.loading && !list.data && <Loader2 className="h-5 w-5 animate-spin text-slate-500" />}
            {list.data?.data.length === 0 && <p className="text-sm text-slate-500">{t('insights.alerts.empty')}</p>}
            <ul className="space-y-2">
                {list.data?.data.map((a) => (
                    <li key={a.id} className="card p-3 text-sm">
                        <div className="flex flex-wrap items-center gap-2">
                            <span className={`rounded border px-1.5 text-xs ${severityClass[a.severity]}`}>{t(`insights.alerts.severity.${a.severity}`)}</span>
                            <span className="font-medium text-slate-900">{a.title}</span>
                            <span className="ml-auto text-xs text-slate-500">{new Date(a.occurred_at).toLocaleString()}</span>
                        </div>
                        <div className="mt-1 text-slate-600">{t(`insights.rule.${a.rule}`)}{a.amount ? ` · ${fcfa(a.amount)}` : ''}{a.detail ? ` · ${a.detail}` : ''}</div>
                        {a.evidence && a.evidence.length > 0 && (
                            <div className="mt-1 text-xs">
                                {t('insights.alerts.evidence')}:{' '}
                                {a.evidence.map((ev, i) => ev.url ? <Link key={i} href={ev.url} className="mr-2 text-blue-700">{ev.label}</Link> : <span key={i} className="mr-2">{ev.label}</span>)}
                            </div>
                        )}
                        {a.acknowledge_note && <div className="mt-1 text-xs text-slate-500">{t('insights.alerts.status.acknowledged')}: {a.acknowledge_note}</div>}
                        {a.resolution_note && <div className="mt-1 text-xs text-slate-500">{t('insights.alerts.status.resolved')}: {a.resolution_note}</div>}
                        {canManage && a.status !== 'resolved' && (
                            <div className="mt-2 flex flex-wrap gap-2">
                                <input className="input flex-1" placeholder={t('insights.alerts.note')} value={notes[a.id] ?? ''}
                                    onChange={(e) => setNotes({ ...notes, [a.id]: e.target.value })} />
                                {a.status === 'open' && <button type="button" className="btn-secondary" onClick={() => act(a, 'acknowledge')}>{t('insights.alerts.acknowledge')}</button>}
                                <button type="button" className="btn-primary" onClick={() => act(a, 'resolve')}>{t('insights.alerts.resolve')}</button>
                            </div>
                        )}
                    </li>
                ))}
            </ul>
        </AppLayout>
    );
}
