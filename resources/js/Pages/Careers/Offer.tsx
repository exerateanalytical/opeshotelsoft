import { Head } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { useT } from '@/lib/i18n';
import Alert from '@/Components/ui/Alert';

interface Offer { applicant: string; title: string | null; hotel: string | null; contract_kind: string; base_salary: number; start_date: string; end_date: string | null; message: string | null }

async function call<T>(path: string, post = false): Promise<T> {
    const r = await fetch(`/api/v1/careers/offers/${path}`, { method: post ? 'POST' : 'GET', headers: { Accept: 'application/json' } });
    const j = await r.json().catch(() => ({}));
    if (!r.ok) throw new Error(j.detail ?? j.message ?? j.title ?? String(r.status));
    return j as T;
}

/** Public page reached from the offer link: read the offer, accept or decline. */
export default function CareersOffer({ token }: { token: string }) {
    const t = useT();
    const [o, setO] = useState<Offer | null>(null);
    const [err, setErr] = useState<string | null>(null);
    const [result, setResult] = useState<string | null>(null);

    useEffect(() => { call<Offer>(token).then(setO).catch((e) => setErr(e.message)); }, [token]);
    const reply = async (what: 'accept' | 'decline') => { setErr(null); try { await call(`${token}/${what}`, true); setResult(t(`talent.offer.${what}_done`)); } catch (e) { setErr(e instanceof Error ? e.message : String(e)); } };

    return (
        <div className="min-h-screen bg-[#faf8f3] px-4 py-6 text-slate-900">
            <Head title={t('talent.offer.title')} />
            <main className="mx-auto max-w-xl rounded-lg border border-slate-200 bg-white p-5">
                <h1 className="mb-3 text-2xl font-semibold">{t('talent.offer.title')}</h1>
                {err && <Alert variant="danger" className="mb-3">{err}</Alert>}
                {result && <Alert variant="success">{result}</Alert>}
                {o && !result && (
                    <div className="space-y-2 text-base">
                        <p>{t('talent.offer.hello', { name: o.applicant })}</p>
                        <p>{o.hotel} - {o.title} ({o.contract_kind})</p>
                        <p>{t('talent.offer.salary')}: <strong>{new Intl.NumberFormat('fr-FR').format(o.base_salary)} XAF</strong></p>
                        <p>{t('talent.offer.start')}: {o.start_date}{o.end_date ? ` - ${o.end_date}` : ''}</p>
                        {o.message && <p className="whitespace-pre-line">{o.message}</p>}
                        <p className="text-sm text-slate-600">{t('talent.offer.next')}</p>
                        <div className="flex gap-3 pt-2"><button className="btn-primary flex-1 min-h-12 text-base" onClick={() => reply('accept')}>{t('talent.offer.accept')}</button><button className="btn-secondary flex-1 py-3" onClick={() => reply('decline')}>{t('talent.offer.decline')}</button></div>
                    </div>)}
            </main>
        </div>
    );
}
