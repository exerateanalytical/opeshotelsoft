import AppLayout from '@/Layouts/AppLayout';
import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, Loader2, Save } from 'lucide-react';
import { useState } from 'react';
import { api, ApiError, useApi } from '@/lib/api';
import { Intent, PROVIDERS } from './shared';
import { useT } from '@/lib/i18n';


interface Gateway {
    provider: Intent['provider'];
    enabled: boolean;
    mode: 'sandbox' | 'live';
    configured: boolean;
    driver: string;
    fields: { name: string; secret: boolean }[];
    credentials: Record<string, string | null>;
    callback_url: string;
}

export default function PaymentGateways() {
    const t = useT();
    const list = useApi<Gateway[]>('payments/gateways');

    return (
        <AppLayout title={t('payments.gateways.title')} subtitle={t('payments.gateways.subtitle')}
            actions={<Link href="/payments" className="btn-secondary"><ArrowLeft className="w-4 h-4" /> {t('payments.gateways.back')}</Link>}>
            <Head title={t('payments.gateways.title')} />
            {list.error && <div className="mb-4 rounded-lg text-sm px-4 py-3 bg-red-50 text-red-700">{list.error.message}</div>}
            {list.loading && !list.data && <Loader2 className="w-5 h-5 mx-auto animate-spin text-slate-400" />}
            <div className="grid gap-6 lg:grid-cols-3">
                {list.data?.map((g) => <GatewayCard key={g.provider} gateway={g} onSaved={() => list.reload()} />)}
            </div>
        </AppLayout>
    );
}

function GatewayCard({ gateway, onSaved }: { gateway: Gateway; onSaved: () => void }) {
    const t = useT();
    const [enabled, setEnabled] = useState(gateway.enabled);
    const [mode, setMode] = useState(gateway.mode);
    const [values, setValues] = useState<Record<string, string>>({});
    const [busy, setBusy] = useState(false);
    const [message, setMessage] = useState<{ ok: boolean; text: string } | null>(null);

    return (
        <form className="card px-5 py-4 space-y-3 self-start" onSubmit={async (e) => {
            e.preventDefault();
            setBusy(true);
            setMessage(null);
            try {
                await api(`payments/gateways/${gateway.provider}`, { method: 'PUT', body: JSON.stringify({ enabled, mode, credentials: values }) });
                setValues({});
                setMessage({ ok: true, text: t('payments.gateways.saved') });
                onSaved();
            } catch (err) {
                setMessage({ ok: false, text: err instanceof ApiError ? err.message : String(err) });
            } finally {
                setBusy(false);
            }
        }}>
            <div className="flex items-center justify-between gap-2">
                <div className="font-medium text-sm">{t(PROVIDERS[gateway.provider])}</div>
                <span className={gateway.configured ? 'badge-green' : 'badge-slate'}>{gateway.configured ? t('payments.gateways.configured') : t('payments.dry_run')}</span>
            </div>
            <div className="grid grid-cols-2 gap-3">
                <label className="label flex items-center gap-2">
                    <input type="checkbox" checked={enabled} onChange={(e) => setEnabled(e.target.checked)} /> {t('payments.gateways.enabled')}
                </label>
                <select className="input" value={mode} onChange={(e) => setMode(e.target.value as Gateway['mode'])}>
                    <option value="sandbox">{t('payments.gateways.sandbox')}</option>
                    <option value="live">{t('payments.gateways.live')}</option>
                </select>
            </div>
            {gateway.fields.map((f) => (
                <label key={f.name} className="label block">{f.name.replace(/_/g, ' ')}
                    <input className="input mt-1" type={f.secret ? 'password' : 'text'} autoComplete="off"
                        placeholder={gateway.credentials[f.name] ?? (f.secret ? t('payments.gateways.not_set') : '')}
                        value={values[f.name] ?? ''} onChange={(e) => setValues({ ...values, [f.name]: e.target.value })} />
                </label>
            ))}
            <p className="text-xs text-slate-500 break-all">{t('payments.gateways.callback_url', { url: gateway.callback_url })}</p>
            <p className="text-xs text-slate-500">{t('payments.gateways.blank_hint')}</p>
            <button className="btn-primary w-full justify-center" disabled={busy}>{busy ? <Loader2 className="w-4 h-4 animate-spin" /> : <Save className="w-4 h-4" />} {t('common.save')}</button>
            {message && <div className={message.ok ? 'rounded-lg text-sm px-4 py-3 bg-emerald-50 text-emerald-700' : 'rounded-lg text-sm px-4 py-3 bg-red-50 text-red-700'}>{message.text}</div>}
        </form>
    );
}
