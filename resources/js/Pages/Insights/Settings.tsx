import AppLayout from '@/Layouts/AppLayout';
import { Head } from '@inertiajs/react';
import QueryStatus from '@/Components/ui/QueryStatus';
import { EmptyFor } from '@/Components/ui/EmptyStates';
import { useEffect, useState } from 'react';
import { api, ApiError, useApi } from '@/lib/api';
import { useT } from '@/lib/i18n';

type RuleCfg = { enabled: boolean; severity: string; source: string; [k: string]: string | number | boolean };
interface Settings { rules: Record<string, RuleCfg>; digest_enabled: boolean; digest_emails: string[]; digest_whatsapp: string[]; digest_locale: string }

const FIELDS = ['min_amount', 'percent', 'floor_amount', 'grace_minutes', 'start', 'end'];

export default function InsightsSettings() {
    const t = useT();
    const q = useApi<Settings>('insights/settings');
    const [s, setS] = useState<Settings | null>(null);
    const [message, setMessage] = useState<{ ok: boolean; text: string } | null>(null);
    useEffect(() => setS(q.data), [q.data]);

    const setRule = (rule: string, key: string, value: string | number | boolean) =>
        s && setS({ ...s, rules: { ...s.rules, [rule]: { ...s.rules[rule], [key]: value } } });
    const lines = (v: string) => v.split('\n').map((x) => x.trim()).filter(Boolean);

    async function run(fn: () => Promise<unknown>, ok: string) {
        try {
            await fn();
            setMessage({ ok: true, text: ok });
        } catch (e) {
            setMessage({ ok: false, text: e instanceof ApiError ? e.message : t('insights.error') });
        }
    }
    const save = () => run(async () => {
        if (!s) return;
        const rules = Object.fromEntries(Object.entries(s.rules).map(([k, v]) => [k, Object.fromEntries(Object.entries(v).filter(([f]) => f === 'enabled' || FIELDS.includes(f)))]));
        setS(await api<Settings>('insights/settings', { method: 'PUT', body: JSON.stringify({ ...s, rules }) }));
    }, t('insights.settings.saved'));

    return (
        <AppLayout title={t('insights.settings.title')} subtitle={t('insights.settings.subtitle')}>
            <Head title={t('insights.settings.title')} />
            {message && <p className={`mb-3 text-sm ${message.ok ? 'text-emerald-700' : 'text-red-700'}`}>{message.text}</p>}
            <QueryStatus query={q} variant="form" rows={4} />
            {s && (
                <div className="space-y-4">
                    {Object.keys(s.rules).length === 0 && <div className="card"><EmptyFor kind="generic" /></div>}
                    <div className="card divide-y divide-slate-100">
                        {Object.entries(s.rules).map(([rule, cfg]) => (
                            <div key={rule} className="flex flex-wrap items-end gap-3 p-3 text-sm">
                                <label className="flex min-w-[16rem] flex-1 items-center gap-2">
                                    <input type="checkbox" checked={cfg.enabled} onChange={(e) => setRule(rule, 'enabled', e.target.checked)} />
                                    <span className="font-medium text-slate-900">{t(`insights.rule.${rule}`)}</span>
                                    <span className="text-xs text-slate-500">{t(`insights.settings.source_${cfg.source}`)}</span>
                                </label>
                                {FIELDS.filter((f) => f in cfg).map((f) => (
                                    <label key={f}>
                                        <span className="label">{t(`insights.settings.${f}`)}</span>
                                        <input className="input w-32" type={f === 'start' || f === 'end' ? 'time' : 'number'} value={String(cfg[f])}
                                            onChange={(e) => setRule(rule, f, f === 'start' || f === 'end' ? e.target.value : Number(e.target.value))} />
                                    </label>
                                ))}
                            </div>
                        ))}
                    </div>
                    <div className="card space-y-3 p-3 text-sm">
                        <h2 className="font-semibold text-slate-900">{t('insights.settings.digest')}</h2>
                        <label className="flex items-center gap-2">
                            <input type="checkbox" checked={s.digest_enabled} onChange={(e) => setS({ ...s, digest_enabled: e.target.checked })} />
                            {t('insights.settings.digest_enabled')}
                        </label>
                        <div className="grid gap-3 sm:grid-cols-2">
                            <label><span className="label">{t('insights.settings.digest_emails')}</span>
                                <textarea className="input w-full" rows={3} value={s.digest_emails.join('\n')} onChange={(e) => setS({ ...s, digest_emails: lines(e.target.value) })} /></label>
                            <label><span className="label">{t('insights.settings.digest_whatsapp')}</span>
                                <textarea className="input w-full" rows={3} value={s.digest_whatsapp.join('\n')} onChange={(e) => setS({ ...s, digest_whatsapp: lines(e.target.value) })} /></label>
                        </div>
                        <label className="block max-w-xs"><span className="label">{t('insights.settings.digest_locale')}</span>
                            <select className="select w-full" value={s.digest_locale} onChange={(e) => setS({ ...s, digest_locale: e.target.value })}>
                                <option value="fr">Français</option><option value="en">English</option>
                            </select></label>
                        <button type="button" className="btn-secondary" onClick={() => run(() => api('insights/digest/send', { method: 'POST', body: '{}' }), t('insights.settings.sent'))}>
                            {t('insights.settings.send_now')}
                        </button>
                    </div>
                    <button type="button" className="btn-primary" onClick={save}>{t('insights.settings.save')}</button>
                </div>
            )}
        </AppLayout>
    );
}
