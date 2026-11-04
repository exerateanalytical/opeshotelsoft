import AppLayout from '@/Layouts/AppLayout';
import { Head } from '@inertiajs/react';
import QueryStatus from '@/Components/ui/QueryStatus';
import { EmptyFor } from '@/Components/ui/EmptyStates';
import { useEffect, useState } from 'react';
import { api, ApiError, useApi } from '@/lib/api';
import { useT } from '@/lib/i18n';
import { OnboardingTabs, openPdf } from './shared';

interface Template { kind: string; locale: 'fr' | 'en'; title: string; body: string; custom: boolean; placeholders: string[] }

export default function Templates() {
    const t = useT();
    const list = useApi<{ templates: Template[] }>('documents/templates');
    const [kind, setKind] = useState('invoice');
    const [locale, setLocale] = useState<'fr' | 'en'>('fr');
    const [title, setTitle] = useState('');
    const [body, setBody] = useState('');
    const [preview, setPreview] = useState('');
    const [msg, setMsg] = useState<{ ok: boolean; text: string } | null>(null);
    const current = list.data?.templates.find((x) => x.kind === kind && x.locale === locale);

    useEffect(() => {
        if (current) {
            setTitle(current.title);
            setBody(current.body);
        }
    }, [current?.kind, current?.locale, current?.body]);

    // Live preview (debounced); the server escapes everything and fills whitelisted placeholders only.
    useEffect(() => {
        if (!body) return;
        const h = setTimeout(() => {
            api<{ html: string }>('documents/templates/preview', { method: 'POST', body: JSON.stringify({ kind, locale, title, body }) })
                .then((r) => { setPreview(r.html); setMsg(null); })
                .catch((e) => setMsg({ ok: false, text: e instanceof ApiError ? (e.problem.errors?.body?.[0] ?? e.message) : t('onboarding.error') }));
        }, 400);
        return () => clearTimeout(h);
    }, [body, kind, locale]);

    async function run(fn: () => Promise<unknown>) {
        try {
            await fn();
            setMsg({ ok: true, text: t('onboarding.saved') });
            list.reload();
        } catch (e) {
            setMsg({ ok: false, text: e instanceof ApiError ? (e.problem.errors?.body?.[0] ?? e.message) : t('onboarding.error') });
        }
    }

    function insert(p: string) {
        setBody((b) => `${b}{{ ${p} }}`);
    }

    return (
        <AppLayout title={t('onboarding.templates.title')} subtitle={t('onboarding.templates.subtitle')}>
            <Head title={t('onboarding.templates.title')} />
            <OnboardingTabs active="/onboarding/templates" />
            <div className="mb-3 flex flex-wrap gap-3">
                <select className="select w-56" aria-label={t('ui2.onboarding.doc_kind')} value={kind} onChange={(e) => setKind(e.target.value)}>
                    {['invoice', 'quote', 'registration_card', 'confirmation', 'letter', 'hr_offer', 'hr_attestation', 'hr_salary_cert', 'hr_raise', 'hr_transfer', 'hr_warning', 'hr_suspension', 'hr_termination', 'hr_resignation'].map((k) => <option key={k} value={k}>{t(`onboarding.templates.kind.${k}`)}</option>)}
                </select>
                <select className="select w-32" aria-label={t('profile.language')} value={locale} onChange={(e) => setLocale(e.target.value as 'fr' | 'en')}>
                    <option value="fr">Français</option>
                    <option value="en">English</option>
                </select>
                {current && <span className="self-center text-sm text-slate-500">{current.custom ? t('onboarding.templates.custom') : t('onboarding.templates.default')}</span>}
            </div>
            {kind === 'invoice' && <p className="mb-3 text-sm text-slate-500">{t('onboarding.templates.invoice_fr')}</p>}
            {msg && <p className={`mb-3 text-sm ${msg.ok ? 'text-emerald-700' : 'text-red-700'}`}>{msg.text}</p>}
            <QueryStatus query={list} variant="form" />
            {list.data && !current && <div className="card mb-3"><EmptyFor kind="generic" /></div>}
            <div className="grid gap-4 lg:grid-cols-2">
                <div className="card p-4 space-y-3">
                    <label className="block">
                        <span className="label">{t('onboarding.templates.f.title')}</span>
                        <input className="input" value={title} onChange={(e) => setTitle(e.target.value)} />
                    </label>
                    <label className="block">
                        <span className="label">{t('onboarding.templates.f.body')}</span>
                        <textarea className="input font-mono text-xs" rows={14} value={body} onChange={(e) => setBody(e.target.value)} />
                    </label>
                    <p className="text-xs text-slate-500">{t('onboarding.templates.syntax')}</p>
                    <div className="flex flex-wrap gap-1">
                        {(current?.placeholders ?? []).map((p) => (
                            <button key={p} type="button" className="inline-flex min-h-11 items-center rounded border border-slate-300 px-3 py-0.5 text-xs text-slate-700 hover:bg-slate-50" onClick={() => insert(p)}>{p}</button>
                        ))}
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <button type="button" className="btn-primary" onClick={() => run(() => api(`documents/templates/${kind}/${locale}`, { method: 'PUT', body: JSON.stringify({ title, body }) }))}>{t('onboarding.save')}</button>
                        <button type="button" className="btn-secondary" onClick={() => run(() => openPdf('documents/templates/preview', { method: 'POST', body: JSON.stringify({ kind, locale, title, body, format: 'pdf' }) }))}>{t('onboarding.templates.preview_pdf')}</button>
                        <button type="button" className="btn-secondary" disabled={!current?.custom} onClick={() => window.confirm(t('onboarding.templates.reset_confirm')) && run(() => api(`documents/templates/${kind}/${locale}/reset`, { method: 'POST' }))}>{t('onboarding.templates.reset')}</button>
                    </div>
                </div>
                <div className="card p-4">
                    <h2 className="mb-2 text-sm font-semibold text-slate-500">{t('onboarding.templates.preview')}</h2>
                    {/* Server-rendered: user text escaped, placeholders filled with sample data. */}
                    <div className="prose-sm text-sm text-slate-800 [&_h2]:font-semibold [&_h2]:mb-2 [&_p]:mb-2" dangerouslySetInnerHTML={{ __html: preview }} />
                </div>
            </div>
        </AppLayout>
    );
}
