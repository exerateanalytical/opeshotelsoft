import AppLayout from '@/Layouts/AppLayout';
import { Head } from '@inertiajs/react';
import { Loader2, Pencil, Plus, Star, X } from 'lucide-react';
import { useState } from 'react';
import { api, useApi } from '@/lib/api';
import { formatDate } from '@/lib/utils';
import { useT } from '@/lib/i18n';
import { Banner, errorText, Field, Forbidden, Paginated } from './shared';

interface Hotel {
    id: number;
    code: string;
    name: string;
    legal_name: string | null;
    tax_id: string | null;
    trade_register: string | null;
    country: string;
    city: string | null;
    address: string | null;
    phone: string | null;
    email: string | null;
    stars: number | null;
    currency: string;
    timezone: string;
    locale: string;
    check_in_time: string | null;
    check_out_time: string | null;
    business_date: string | null;
}

const COUNTRIES: Record<string, string> = { CM: 'Cameroon', GA: 'Gabon', CG: 'Congo', TD: 'Chad', CF: 'Central African Rep.', GQ: 'Equatorial Guinea' };
const TEXT_FIELDS = ['code', 'name', 'legal_name', 'tax_id', 'trade_register', 'city', 'address', 'phone', 'email'] as const;
type Draft = Record<string, string>;
const empty: Draft = {
    code: '', name: '', legal_name: '', tax_id: '', trade_register: '', city: '', address: '', phone: '', email: '',
    country: 'CM', stars: '', currency: 'XAF', timezone: 'Africa/Douala', locale: 'fr', check_in_time: '14:00', check_out_time: '12:00',
};
const hm = (t: string | null) => (t ?? '').slice(0, 5);

export default function SettingsHotels() {
    const t = useT();
    const hotels = useApi<Paginated<Hotel>>('hotels');
    const [editing, setEditing] = useState<Hotel | 'new' | null>(null);
    const [draft, setDraft] = useState<Draft>(empty);
    const [busy, setBusy] = useState(false);
    const [message, setMessage] = useState<{ ok: boolean; text: string } | null>(null);
    const forbidden = hotels.error?.problem.status === 403;

    function open(h: Hotel | 'new') {
        setMessage(null);
        setEditing(h);
        if (h === 'new') return setDraft(empty);
        const d: Draft = { ...empty };
        for (const k of Object.keys(empty)) d[k] = String((h as unknown as Record<string, unknown>)[k] ?? '');
        d.check_in_time = hm(h.check_in_time);
        d.check_out_time = hm(h.check_out_time);
        setDraft(d);
    }

    async function save(e: React.FormEvent) {
        e.preventDefault();
        if (!editing) return;
        setBusy(true);
        setMessage(null);
        const body: Record<string, unknown> = {
            country: draft.country, currency: draft.currency.toUpperCase(), timezone: draft.timezone, locale: draft.locale,
            stars: draft.stars ? Number(draft.stars) : null,
        };
        for (const k of TEXT_FIELDS) body[k] = draft[k].trim() || null;
        if (draft.check_in_time) body.check_in_time = draft.check_in_time;
        if (draft.check_out_time) body.check_out_time = draft.check_out_time;
        try {
            if (editing === 'new') await api('hotels', { method: 'POST', body: JSON.stringify(body) });
            else await api(`hotels/${editing.id}`, { method: 'PATCH', body: JSON.stringify(body) });
            setMessage({ ok: true, text: t('settings.hotels.saved', { name: draft.name }) });
            setEditing(null);
            hotels.reload();
        } catch (err) {
            const status = (err as { problem?: { status?: number } }).problem?.status;
            setMessage({ ok: false, text: status === 403 ? t('settings.hotels.forbidden') : errorText(err) });
        } finally {
            setBusy(false);
        }
    }

    const set = (k: string) => (e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement>) => setDraft({ ...draft, [k]: e.target.value });

    return (
        <AppLayout
            title={t('settings.hotels.title')}
            subtitle={t('settings.hotels.subtitle')}
            actions={!forbidden && <button className="btn-primary" onClick={() => open('new')}><Plus className="w-4 h-4" /> {t('settings.hotels.new')}</button>}
        >
            <Head title={t('settings.hotels.title')} />
            {forbidden ? <Forbidden permission="hotels.manage" /> : (
                <>
                    {message && <Banner {...message} />}
                    {hotels.error && <Banner ok={false} text={hotels.error.message} />}

                    {editing && (
                        <form onSubmit={save} className="card px-5 py-4 mb-6 space-y-4">
                            <div className="flex items-center">
                                <h3 className="font-semibold text-slate-800">{editing === 'new' ? t('settings.hotels.new') : t('settings.hotels.edit', { name: editing.name })}</h3>
                                <button type="button" className="ml-auto text-slate-500 hover:text-slate-600" onClick={() => setEditing(null)}><X className="w-5 h-5" /></button>
                            </div>
                            <div className="grid sm:grid-cols-2 lg:grid-cols-3 gap-3">
                                <Field label={t('settings.hotels.code')}><input className="input w-full" required maxLength={12} value={draft.code} onChange={set('code')} /></Field>
                                <Field label={t('settings.hotels.name')}><input className="input w-full" required maxLength={150} value={draft.name} onChange={set('name')} /></Field>
                                <Field label={t('settings.hotels.stars')}>
                                    <select className="input w-full" value={draft.stars} onChange={set('stars')}>
                                        <option value="">{t('settings.hotels.unrated')}</option>
                                        {[1, 2, 3, 4, 5].map((n) => <option key={n} value={n}>{n}</option>)}
                                    </select>
                                </Field>
                                <Field label={t('settings.hotels.legal_name')}><input className="input w-full" value={draft.legal_name} onChange={set('legal_name')} /></Field>
                                <Field label={t('settings.hotels.tax_id')}><input className="input w-full" value={draft.tax_id} onChange={set('tax_id')} /></Field>
                                <Field label={t('settings.hotels.trade_register')}><input className="input w-full" value={draft.trade_register} onChange={set('trade_register')} /></Field>
                                <Field label={t('settings.hotels.country')}>
                                    <select className="input w-full" value={draft.country} onChange={set('country')}>
                                        {Object.entries(COUNTRIES).map(([c, n]) => <option key={c} value={c}>{n}</option>)}
                                    </select>
                                </Field>
                                <Field label={t('settings.hotels.city')}><input className="input w-full" value={draft.city} onChange={set('city')} /></Field>
                                <Field label={t('settings.hotels.address')}><input className="input w-full" value={draft.address} onChange={set('address')} /></Field>
                                <Field label={t('settings.hotels.phone')}><input className="input w-full" value={draft.phone} onChange={set('phone')} /></Field>
                                <Field label={t('settings.hotels.email')}><input className="input w-full" type="email" value={draft.email} onChange={set('email')} /></Field>
                                <Field label={t('settings.hotels.currency')}><input className="input w-full" required maxLength={3} minLength={3} value={draft.currency} onChange={set('currency')} /></Field>
                                <Field label={t('settings.hotels.timezone')}><input className="input w-full" required value={draft.timezone} onChange={set('timezone')} /></Field>
                                <Field label={t('settings.hotels.language')}>
                                    <select className="input w-full" value={draft.locale} onChange={set('locale')}>
                                        <option value="fr">Français</option><option value="en">English</option>
                                    </select>
                                </Field>
                                <div className="grid grid-cols-2 gap-3">
                                    <Field label={t('settings.hotels.check_in')}><input className="input w-full" type="time" value={draft.check_in_time} onChange={set('check_in_time')} /></Field>
                                    <Field label={t('settings.hotels.check_out')}><input className="input w-full" type="time" value={draft.check_out_time} onChange={set('check_out_time')} /></Field>
                                </div>
                            </div>
                            <div className="flex gap-2">
                                <button className="btn-primary" disabled={busy}>{busy && <Loader2 className="w-4 h-4 animate-spin" />} Save</button>
                                <button type="button" className="btn-secondary" onClick={() => setEditing(null)}>{t('common.cancel')}</button>
                            </div>
                        </form>
                    )}

                    <div className="card overflow-x-auto">
                        <table className="table-auto w-full">
                            <thead><tr><th>{t('settings.hotels.hotel')}</th><th>{t('settings.hotels.location')}</th><th>{t('settings.hotels.stars')}</th><th>{t('settings.hotels.currency')}</th><th>{t('settings.hotels.check_in_out')}</th><th>{t('settings.hotels.business_date')}</th><th></th></tr></thead>
                            <tbody>
                                {hotels.loading && !hotels.data && <tr><td colSpan={7}><Loader2 className="w-5 h-5 mx-auto my-8 animate-spin text-slate-500" /></td></tr>}
                                {hotels.data?.data.length === 0 && <tr><td colSpan={7} className="text-center py-10 text-slate-500 text-sm">{t('settings.hotels.empty')}</td></tr>}
                                {hotels.data?.data.map((h) => (
                                    <tr key={h.id}>
                                        <td><div className="text-sm font-medium text-slate-800">{h.name}</div><div className="text-xs font-mono text-slate-500">{h.code}{h.legal_name ? ` · ${h.legal_name}` : ''}</div></td>
                                        <td className="text-sm">{[h.city, COUNTRIES[h.country] ?? h.country].filter(Boolean).join(', ')}</td>
                                        <td>{h.stars ? <span className="flex text-amber-500">{Array.from({ length: h.stars }, (_, i) => <Star key={i} className="w-3.5 h-3.5 fill-current" />)}</span> : <span className="text-xs text-slate-500">—</span>}</td>
                                        <td className="text-sm">{h.currency}</td>
                                        <td className="text-sm">{hm(h.check_in_time) || '—'} / {hm(h.check_out_time) || '—'}</td>
                                        <td className="text-sm font-medium">{h.business_date ? formatDate(h.business_date) : '—'}</td>
                                        <td className="text-right"><button title={t('common.edit')} className="text-slate-500 hover:text-indigo-600" onClick={() => open(h)}><Pencil className="w-4 h-4" /></button></td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </>
            )}
        </AppLayout>
    );
}
