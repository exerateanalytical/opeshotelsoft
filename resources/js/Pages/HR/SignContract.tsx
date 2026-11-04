import { Head } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { useT } from '@/lib/i18n';
import SignaturePad from './SignaturePad';
import Alert from '@/Components/ui/Alert';

interface View {
    number: string; title: string; body: string; status: string; employee: string; hotel: string | null;
    channels: { channel: string; to: string }[];
}

/** Public phone page reached from the signing link: read → receive code → draw signature → sign. No login. */
async function call<T>(path: string, body?: unknown): Promise<T> {
    const r = await fetch(`/api/v1/hr/sign/${path}`, {
        method: body ? 'POST' : 'GET',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
        body: body ? JSON.stringify(body) : undefined,
    });
    const j = await r.json().catch(() => ({}));
    if (!r.ok) throw new Error(j.detail ?? j.message ?? j.title ?? String(r.status));
    return j as T;
}

export default function SignContract({ token }: { token: string }) {
    const t = useT();
    const [doc, setDoc] = useState<View | null>(null);
    const [err, setErr] = useState<string | null>(null);
    const [info, setInfo] = useState<string | null>(null);
    const [channel, setChannel] = useState('sms');
    const [otp, setOtp] = useState('');
    const [sig, setSig] = useState<string | null>(null);
    const [agree, setAgree] = useState(false);
    const [busy, setBusy] = useState(false);
    const [done, setDone] = useState(false);

    useEffect(() => {
        call<View>(token).then((d) => { setDoc(d); setChannel(d.channels[0]?.channel ?? 'sms'); setDone(d.status !== 'sent'); }).catch((e) => setErr(e.message));
    }, [token]);

    async function sendCode() {
        setBusy(true); setErr(null);
        try { await call(`${token}/otp`, { channel }); setInfo(t('hr2.sign.code_sent')); } catch (e) { setErr((e as Error).message); } finally { setBusy(false); }
    }
    async function sign(e: React.FormEvent) {
        e.preventDefault();
        setBusy(true); setErr(null);
        try { await call(token, { otp, signature: sig, agree }); setDone(true); } catch (x) { setErr((x as Error).message); } finally { setBusy(false); }
    }

    return (
        <div className="min-h-screen bg-slate-50 px-4 py-6">
            <Head title={t('hr2.sign.title')} />
            <div className="mx-auto max-w-2xl">
                {doc?.hotel && <p className="text-sm text-slate-500">{doc.hotel}</p>}
                <h1 className="mb-4 text-xl font-semibold">{doc?.title ?? t('hr2.sign.title')}</h1>
                {err && <Alert variant="danger" className="mb-4">{err}</Alert>}
                {info && !err && <Alert variant="success" className="mb-4">{info}</Alert>}
                {doc && (
                    <>
                        <div className="mb-4 max-h-[50vh] overflow-y-auto whitespace-pre-wrap rounded-lg border bg-white p-4 text-sm leading-relaxed">{doc.body}</div>
                        {done ? (
                            <Alert variant="success">{t('hr2.sign.done')}</Alert>
                        ) : (
                            <form onSubmit={sign} className="space-y-4 rounded-lg border bg-white p-4">
                                <div>
                                    <p className="mb-2 text-sm font-medium">{t('hr2.sign.step_code')}</p>
                                    <div className="flex flex-wrap gap-2">
                                        <select className="select flex-1" value={channel} onChange={(e) => setChannel(e.target.value)}>
                                            {doc.channels.map((c) => <option key={c.channel} value={c.channel}>{t(`hr2.channel.${c.channel}`)} · {c.to}</option>)}
                                        </select>
                                        <button type="button" className="btn-secondary min-h-11" disabled={busy || !doc.channels.length} onClick={sendCode}>{t('hr2.sign.send_code')}</button>
                                    </div>
                                    <input className="input mt-2 w-full tracking-widest" inputMode="numeric" autoComplete="one-time-code" maxLength={6}
                                        placeholder={t('hr2.sign.code')} value={otp} onChange={(e) => setOtp(e.target.value.replace(/\D/g, ''))} />
                                </div>
                                <div>
                                    <p className="mb-2 text-sm font-medium">{t('hr2.sign.step_draw')}</p>
                                    <SignaturePad onChange={setSig} clearLabel={t('hr2.sign.clear')} />
                                </div>
                                <label className="flex items-start gap-2 text-sm">
                                    <input type="checkbox" checked={agree} onChange={(e) => setAgree(e.target.checked)} className="mt-0.5 h-5 w-5 shrink-0 accent-navy-800" />
                                    {t('hr2.sign.agree')}
                                </label>
                                <button className="btn-primary w-full min-h-12 text-base" disabled={busy || otp.length !== 6 || !sig || !agree}>{t('hr2.sign.submit')}</button>
                            </form>
                        )}
                    </>
                )}
            </div>
        </div>
    );
}
