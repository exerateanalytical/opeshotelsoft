import AppLayout from '@/Layouts/AppLayout';
import { Head } from '@inertiajs/react';
import QueryStatus from '@/Components/ui/QueryStatus';
import EmptyState from '@/Components/ui/EmptyState';
import { Image as ImageIcon } from 'lucide-react';
import { useEffect, useState } from 'react';
import { api, ApiError, useApi } from '@/lib/api';
import { useT } from '@/lib/i18n';
import { OnboardingTabs, openPdf, upload } from './shared';
import WatermarkForm from './Watermark';

const DESIGNS = ['classic', 'modern', 'executive', 'minimal', 'heritage', 'corporate', 'resort'] as const;

const FIELDS = ['legal_name', 'tax_id', 'trade_register', 'address', 'city', 'postal_box', 'phone', 'email'] as const;
type Branding = Record<string, string | null> & { logo: string | null; seal: string | null };

export default function BrandingPage() {
    const t = useT();
    const b = useApi<Branding>('documents/branding');
    const [f, setF] = useState<Record<string, string>>({});
    const [msg, setMsg] = useState<{ ok: boolean; text: string } | null>(null);
    const [busy, setBusy] = useState(false);

    useEffect(() => {
        if (b.data) setF(Object.fromEntries(Object.entries(b.data).filter(([k]) => k !== 'logo' && k !== 'seal').map(([k, v]) => [k, v == null ? '' : String(v)])));
    }, [b.data]);
    const [preview, setPreview] = useState('');
    const design = f.letterhead_design || 'classic';
    useEffect(() => {
        const q = new URLSearchParams({ design, brand_color: f.brand_color || '', accent_color: f.accent_color || '' });
        const h = setTimeout(() => api<{ html: string }>(`documents/branding/preview?${q}`).then((r) => setPreview(r.html)).catch(() => undefined), 250);
        return () => clearTimeout(h);
    }, [design, f.brand_color, f.accent_color, b.data]);
    const set = (k: string) => (e: { target: { value: string } }) => setF((p) => ({ ...p, [k]: e.target.value }));

    async function run(fn: () => Promise<unknown>) {
        setBusy(true);
        setMsg(null);
        try {
            await fn();
            setMsg({ ok: true, text: t('onboarding.saved') });
            b.reload();
        } catch (e) {
            const err = e instanceof ApiError ? e : null;
            setMsg({ ok: false, text: (err?.problem.errors ? Object.values(err.problem.errors).flat().join(' ') : err?.message) || t('onboarding.error') });
        } finally {
            setBusy(false);
        }
    }

    async function seal(file: File) {
        const form = new FormData();
        form.append('seal', file);
        await run(async () => {
            const r = await upload<{ message?: string }>('documents/branding/seal', form);
            if (!r.ok) throw new Error(r.body?.message ?? t('onboarding.error'));
        });
    }

    async function logo(file: File) {
        const form = new FormData();
        form.append('logo', file);
        await run(async () => {
            const r = await upload<{ message?: string }>('documents/branding/logo', form);
            if (!r.ok) throw new Error(r.body?.message ?? t('onboarding.error'));
        });
    }

    const save = () => run(() => api('documents/branding', {
        method: 'PUT',
        body: JSON.stringify({ ...Object.fromEntries([...FIELDS, 'brand_color', 'accent_color', 'bank_details', 'document_footer'].map((k) => [k, f[k] || null])), letterhead_design: design }),
    }));

    return (
        <AppLayout title={t('onboarding.branding.title')} subtitle={t('onboarding.branding.subtitle')}>
            <Head title={t('onboarding.branding.title')} />
            <OnboardingTabs active="/onboarding/branding" />
            {msg && <p className={`mb-3 text-sm ${msg.ok ? 'text-emerald-700' : 'text-red-700'}`}>{msg.text}</p>}
            <QueryStatus query={b} variant="form" />
            <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
                <div className="card min-w-0 p-4 space-y-3">
                    <div className="grid gap-3 sm:grid-cols-2">
                        {FIELDS.map((k) => (
                            <label key={k} className="block">
                                <span className="label">{t(`onboarding.f.${k}`)}</span>
                                <input className="input" value={f[k] ?? ''} onChange={set(k)} />
                            </label>
                        ))}
                        {(['brand_color', 'accent_color'] as const).map((k) => (
                            <label key={k} className="block">
                                <span className="label">{t(`onboarding.f.${k}`)}</span>
                                <div className="flex gap-2">
                                    <input type="color" className="h-11 w-12 shrink-0 border border-slate-300" aria-label={t(`onboarding.f.${k}`)} value={f[k] || '#1f2937'} onChange={set(k)} />
                                    <input className="input" value={f[k] ?? ''} onChange={set(k)} placeholder="#1f2937" aria-label={t(`onboarding.f.${k}`)} />
                                </div>
                            </label>
                        ))}
                    </div>
                    <label className="block">
                        <span className="label">{t('onboarding.f.bank_details')}</span>
                        <textarea className="input" rows={2} value={f.bank_details ?? ''} onChange={set('bank_details')} />
                    </label>
                    <label className="block">
                        <span className="label">{t('onboarding.f.document_footer')}</span>
                        <textarea className="input" rows={2} value={f.document_footer ?? ''} onChange={set('document_footer')} />
                    </label>
                    <div className="flex gap-2">
                        <button type="button" className="btn-primary" disabled={busy} onClick={save}>{t('onboarding.save')}</button>
                        <button type="button" className="btn-secondary" disabled={busy} onClick={() => openPdf('documents/templates/letter/print')}>{t('onboarding.branding.preview_pdf')}</button>
                    </div>
                </div>
                <div className="min-w-0 space-y-4">
                    <div className="card p-4 space-y-3">
                        <h2 className="font-semibold text-slate-900">{t('onboarding.branding.logo')}</h2>
                        {b.data?.logo ? <img src={b.data.logo} alt="" className="max-h-20" /> : <div className="border border-dashed border-slate-300 [&>div]:py-4"><EmptyState icon={ImageIcon} title={t('onboarding.branding.no_logo')} /></div>}
                        <input type="file" className="block min-h-11 w-full min-w-0 text-sm" aria-label={t('onboarding.branding.logo')} accept="image/png,image/jpeg" onChange={(e) => e.target.files?.[0] && logo(e.target.files[0])} />
                        {b.data?.logo && <button type="button" className="btn-secondary" disabled={busy} onClick={() => run(() => api('documents/branding/logo/remove', { method: 'POST' }))}>{t('onboarding.branding.remove_logo')}</button>}
                        <p className="text-xs text-slate-500">{t('onboarding.branding.logo_help')}</p>
                    </div>
                    <div className="card p-4 space-y-3">
                        <h2 className="font-semibold text-slate-900">{t('onboarding.branding.seal')}</h2>
                        {b.data?.seal ? <img src={b.data.seal} alt="" className="max-h-20" /> : <div className="border border-dashed border-slate-300 [&>div]:py-4"><EmptyState icon={ImageIcon} title={t('onboarding.branding.no_seal')} /></div>}
                        <input type="file" className="block min-h-11 w-full min-w-0 text-sm" aria-label={t('onboarding.branding.seal')} accept="image/png,image/jpeg" onChange={(e) => e.target.files?.[0] && seal(e.target.files[0])} />
                        {b.data?.seal && <button type="button" className="btn-secondary" disabled={busy} onClick={() => run(() => api('documents/branding/seal/remove', { method: 'POST' }))}>{t('onboarding.branding.remove_seal')}</button>}
                        <p className="text-xs text-slate-500">{t('onboarding.branding.seal_help')}</p>
                    </div>
                    <div className="card p-4 space-y-3">
                        <h2 className="font-semibold text-slate-900">{t('documents2.design.title')}</h2>
                        <p className="text-xs text-slate-500">{t('documents2.design.hint')}</p>
                        <div className="flex flex-wrap gap-2">
                            {DESIGNS.map((d) => (
                                <button key={d} type="button" onClick={() => setF((p) => ({ ...p, letterhead_design: d }))}
                                    className={`min-h-11 border px-3 py-1 text-sm ${design === d ? 'border-slate-900 bg-slate-900 text-white' : 'border-slate-300 text-slate-700'}`}>{t(`documents2.design.${d}`)}</button>
                            ))}
                        </div>
                        <div className="text-xs text-slate-500">{t('documents2.design.preview')}</div>
                        <div data-doc-preview className="overflow-hidden border border-slate-200" style={{ height: 300 }}>
                            <div style={{ transform: 'scale(0.38)', transformOrigin: 'top left', width: '210mm' }} dangerouslySetInnerHTML={{ __html: preview }} />
                        </div>
                        <button type="button" className="btn-secondary" onClick={() => openPdf(`documents/branding/sample?design=${design}`)}>{t('documents2.design.sample_pdf')}</button>
                    </div>
                    <div className="card p-4 text-sm" style={{ borderTop: `3px solid ${f.brand_color || '#1f2937'}` }}>
                        <div className="flex gap-3">
                            {b.data?.logo && <img src={b.data.logo} alt="" className="max-h-12" />}
                            <div>
                                <div className="font-semibold" style={{ color: f.brand_color || '#1f2937' }}>{f.legal_name || b.data?.name}</div>
                                <div>{f.address} {f.city} {f.postal_box}</div>
                                <div>{f.phone} {f.email}</div>
                            </div>
                            <div className="ml-auto text-right text-xs" style={{ color: f.accent_color || '#6b7280' }}>
                                {f.tax_id && <div>NIU : {f.tax_id}</div>}
                                {f.trade_register && <div>RCCM : {f.trade_register}</div>}
                            </div>
                        </div>
                        <div className="mt-6 text-center text-xs" style={{ color: f.accent_color || '#6b7280' }}>{f.document_footer} {f.bank_details}</div>
                    </div>
                </div>
            </div>
            <div className="card mt-4 p-4">
                <h2 className="mb-2 font-semibold text-slate-900">{t('documents2.wm.title')}</h2>
                <WatermarkForm />
            </div>
        </AppLayout>
    );
}
