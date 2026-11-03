import AppLayout from '@/Layouts/AppLayout';
import { Head, Link } from '@inertiajs/react';
import { Ban, CalendarDays, Copy, Download, Loader2, Pencil, Plus, Search, Star, Users, Building2, X } from 'lucide-react';
import { useState } from 'react';
import { activeHotelId, api, ApiError, useApi } from '@/lib/api';
import { cn, formatDate } from '@/lib/utils';
import CompaniesTab, { Field } from './Companies';
import { useT } from '@/lib/i18n';

interface Guest {
    id: number;
    title: string | null;
    first_name: string;
    last_name: string;
    email: string | null;
    phone: string | null;
    gender: string | null;
    date_of_birth: string | null;
    nationality: string | null;
    country: string | null;
    city: string | null;
    address: string | null;
    language: string | null;
    id_type: string | null;
    id_number: string | null;
    id_expiry: string | null;
    id_issued_in: string | null;
    profession: string | null;
    vip: boolean;
    blacklisted: boolean;
    blacklist_reason: string | null;
    notes: string | null;
    marketing_consent: boolean;
    company_id: number | null;
    company?: { id: number; name: string } | null;
}

interface CompanyOption { id: number; name: string }

interface Page<T> { data: T[]; total: number; current_page: number; last_page: number }

const ID_TYPES: [string, string][] = [
    ['passport', 'guests.id.passport'], ['national_id', 'guests.id.national_id'], ['residence_permit', 'guests.id.residence_permit'], ['driving_licence', 'guests.id.driving_licence'], ['other', 'guests.id.other'],
];

const EMPTY = {
    title: '', first_name: '', last_name: '', email: '', phone: '', gender: '', date_of_birth: '', nationality: '', country: '', city: '',
    address: '', language: 'fr', id_type: '', id_number: '', id_expiry: '', id_issued_in: '', profession: '', vip: false, blacklisted: false,
    blacklist_reason: '', notes: '', marketing_consent: false, company_id: '',
};
type Form = typeof EMPTY;

const d = (s: string | null) => (s ? s.slice(0, 10) : '');

function toForm(g: Guest): Form {
    return {
        title: g.title ?? '', first_name: g.first_name, last_name: g.last_name, email: g.email ?? '', phone: g.phone ?? '', gender: g.gender ?? '',
        date_of_birth: d(g.date_of_birth), nationality: g.nationality ?? '', country: g.country ?? '', city: g.city ?? '', address: g.address ?? '',
        language: g.language ?? 'fr', id_type: g.id_type ?? '', id_number: g.id_number ?? '', id_expiry: d(g.id_expiry), id_issued_in: g.id_issued_in ?? '',
        profession: g.profession ?? '', vip: !!g.vip, blacklisted: !!g.blacklisted, blacklist_reason: g.blacklist_reason ?? '', notes: g.notes ?? '',
        marketing_consent: !!g.marketing_consent, company_id: g.company_id ? String(g.company_id) : '',
    };
}

function toBody(f: Form) {
    const nul = (v: string) => (v.trim() === '' ? null : v.trim());
    const iso = (v: string) => (v.trim() === '' ? null : v.trim().toUpperCase());
    return {
        title: nul(f.title), first_name: f.first_name.trim(), last_name: f.last_name.trim(), email: nul(f.email), phone: nul(f.phone),
        gender: nul(f.gender), date_of_birth: nul(f.date_of_birth), nationality: iso(f.nationality), country: iso(f.country), city: nul(f.city),
        address: nul(f.address), language: f.language, id_type: nul(f.id_type), id_number: nul(f.id_number), id_expiry: nul(f.id_expiry),
        id_issued_in: iso(f.id_issued_in), profession: nul(f.profession), vip: f.vip, blacklisted: f.blacklisted,
        blacklist_reason: f.blacklisted ? nul(f.blacklist_reason) : null, notes: nul(f.notes), marketing_consent: f.marketing_consent,
        company_id: f.company_id ? Number(f.company_id) : null,
    };
}

const fullName = (g: Guest) => `${g.title ? g.title + ' ' : ''}${g.first_name} ${g.last_name}`;

export default function GuestsIndex() {
    const t = useT();
    const [tab, setTab] = useState<'guests' | 'companies'>('guests');
    return (
        <AppLayout
            title={t('guests.title')}
            subtitle={t('guests.subtitle')}
            actions={
                <div className="flex gap-1 rounded-lg bg-slate-100 p-1 text-sm">
                    {([['guests', 'guests.tab.guests', Users], ['companies', 'guests.tab.companies', Building2]] as const).map(([k, l, Icon]) => (
                        <button key={k} onClick={() => setTab(k)} className={cn('px-3 py-1 rounded-md flex items-center gap-1', tab === k ? 'bg-white shadow text-emerald-700' : 'text-slate-600')}>
                            <Icon className="w-4 h-4" /> {t(l)}
                        </button>
                    ))}
                </div>
            }
        >
            <Head title={t('guests.title')} />
            {tab === 'guests' ? <GuestsTab /> : <CompaniesTab />}
        </AppLayout>
    );
}

function GuestsTab() {
    const t = useT();
    const [q, setQ] = useState('');
    const [flag, setFlag] = useState('');
    const [page, setPage] = useState(1);
    const [editing, setEditing] = useState<Guest | 'new' | null>(null);
    const [form, setForm] = useState<Form>(EMPTY);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [ok, setOk] = useState<string | null>(null);
    const [dupFor, setDupFor] = useState<Guest | null>(null);
    const [busy, setBusy] = useState<number | null>(null);

    const params = new URLSearchParams({ page: String(page), per_page: '25', sort: 'last_name' });
    if (q) params.set('q', q);
    if (flag) params.set(`filter[${flag}]`, '1');
    const list = useApi<Page<Guest>>(`guests?${params}`);
    const companies = useApi<Page<CompanyOption>>(editing ? 'companies?per_page=200&sort=name&filter[active]=1' : null, [!!editing]);
    const dups = useApi<Guest[]>(dupFor ? `guests/${dupFor.id}/duplicates` : null, [dupFor?.id]);

    const set = <K extends keyof Form>(k: K, v: Form[K]) => setForm((f) => ({ ...f, [k]: v }));

    function open(g: Guest | 'new') {
        setEditing(g);
        setForm(g === 'new' ? EMPTY : toForm(g));
        setDupFor(null);
        setError(null);
        setOk(null);
    }

    async function save(e: React.FormEvent) {
        e.preventDefault();
        if (form.blacklisted && !form.blacklist_reason.trim()) {
            setError(t('guests.blacklist_reason_required'));
            return;
        }
        setSaving(true);
        setError(null);
        try {
            const body = JSON.stringify(toBody(form));
            const saved = editing === 'new' ? await api<Guest>('guests', { method: 'POST', body }) : await api<Guest>(`guests/${(editing as Guest).id}`, { method: 'PUT', body });
            setOk(editing === 'new' ? t('guests.created') : t('guests.updated'));
            setEditing(null);
            if (editing === 'new' && saved?.id) setDupFor(saved);
            list.reload();
        } catch (err) {
            setError(err instanceof ApiError ? err.message : String(err));
        } finally {
            setSaving(false);
        }
    }

    async function merge(survivor: Guest, dup: Guest) {
        if (!confirm(t('guests.merge_confirm', { dup: fullName(dup), survivor: fullName(survivor) }))) return;
        setBusy(dup.id);
        setError(null);
        try {
            await api(`guests/${survivor.id}/merge`, { method: 'POST', body: JSON.stringify({ duplicate_id: dup.id }) });
            setOk(t('guests.merged', { dup: fullName(dup), survivor: fullName(survivor) }));
            dups.reload();
            list.reload();
        } catch (err) {
            setError(err instanceof ApiError ? err.message : String(err));
        } finally {
            setBusy(null);
        }
    }

    async function exportData(g: Guest) {
        setBusy(g.id);
        setError(null);
        try {
            // The API sends the file as an attachment; download it as-is.
            const hotelId = activeHotelId();
            const res = await fetch(`/api/v1/guests/${g.id}/export`, {
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', ...(hotelId ? { 'X-Hotel-Id': hotelId } : {}) },
            });
            if (!res.ok) {
                const body = await res.json().catch(() => ({}));
                throw new ApiError({ status: res.status, title: res.statusText, ...body });
            }
            const name = /filename="?([^";]+)"?/.exec(res.headers.get('Content-Disposition') ?? '')?.[1] ?? `guest-${g.id}-export.json`;
            const url = URL.createObjectURL(await res.blob());
            const a = document.createElement('a');
            a.href = url;
            a.download = name;
            a.click();
            URL.revokeObjectURL(url);
        } catch (err) {
            setError(err instanceof ApiError ? err.message : String(err));
        } finally {
            setBusy(null);
        }
    }

    return (
        <div className="space-y-4">
            {ok && <div className="rounded-lg bg-emerald-50 text-emerald-700 px-4 py-2 text-sm">{ok}</div>}
            {error && <div className="rounded-lg bg-red-50 text-red-700 px-4 py-2 text-sm">{error}</div>}

            <div className="card p-4 flex flex-wrap gap-3 items-center">
                <div className="relative flex-1 min-w-[200px]">
                    <Search className="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
                    <input className="input pl-9 w-full" placeholder={t('guests.search')} value={q} onChange={(e) => { setQ(e.target.value); setPage(1); }} />
                </div>
                <select className="input" value={flag} onChange={(e) => { setFlag(e.target.value); setPage(1); }}>
                    <option value="">{t('guests.filter.all')}</option>
                    <option value="vip">{t('guests.filter.vip')}</option>
                    <option value="blacklisted">{t('guests.filter.blacklisted')}</option>
                </select>
                <button className="btn-primary flex items-center gap-1" onClick={() => open('new')}><Plus className="w-4 h-4" /> {t('guests.new')}</button>
            </div>

            {editing && (
                <form onSubmit={save} className="card p-4 space-y-4">
                    <div className="flex items-center justify-between">
                        <h3 className="font-semibold text-slate-800">{editing === 'new' ? t('guests.new') : t('guests.companies.edit_title', { name: fullName(editing) })}</h3>
                        <button type="button" onClick={() => setEditing(null)}><X className="w-4 h-4 text-slate-400" /></button>
                    </div>
                    <Section title={t('guests.section.identity')}>
                        <Field label={t('guests.field.title')}><input className="input w-full" placeholder={t('guests.field.title_ph')} value={form.title} onChange={(e) => set('title', e.target.value)} /></Field>
                        <Field label={t('guests.field.first_name_req')}><input className="input w-full" required value={form.first_name} onChange={(e) => set('first_name', e.target.value)} /></Field>
                        <Field label={t('guests.field.last_name_req')}><input className="input w-full" required value={form.last_name} onChange={(e) => set('last_name', e.target.value)} /></Field>
                        <Field label={t('guests.field.gender')}>
                            <select className="input w-full" value={form.gender} onChange={(e) => set('gender', e.target.value)}>
                                <option value="">—</option><option value="M">{t('guests.gender.m')}</option><option value="F">{t('guests.gender.f')}</option><option value="X">{t('guests.gender.x')}</option>
                            </select>
                        </Field>
                        <Field label={t('guests.field.dob')}><input type="date" className="input w-full" value={form.date_of_birth} onChange={(e) => set('date_of_birth', e.target.value)} /></Field>
                        <Field label={t('guests.field.nationality_iso')}><input className="input w-full" maxLength={2} placeholder="CM" value={form.nationality} onChange={(e) => set('nationality', e.target.value)} /></Field>
                        <Field label={t('guests.field.profession')}><input className="input w-full" value={form.profession} onChange={(e) => set('profession', e.target.value)} /></Field>
                        <Field label={t('guests.field.language')}>
                            <select className="input w-full" value={form.language} onChange={(e) => set('language', e.target.value)}>
                                <option value="fr">Français</option><option value="en">English</option>
                            </select>
                        </Field>
                    </Section>
                    <Section title={t('guests.section.id')}>
                        <Field label={t('guests.field.id_type')}>
                            <select className="input w-full" value={form.id_type} onChange={(e) => set('id_type', e.target.value)}>
                                <option value="">—</option>
                                {ID_TYPES.map(([v, l]) => <option key={v} value={v}>{t(l)}</option>)}
                            </select>
                        </Field>
                        <Field label={t('guests.field.id_number')}><input className="input w-full" value={form.id_number} onChange={(e) => set('id_number', e.target.value)} /></Field>
                        <Field label={t('guests.field.id_expiry')}><input type="date" className="input w-full" value={form.id_expiry} onChange={(e) => set('id_expiry', e.target.value)} /></Field>
                        <Field label={t('guests.field.id_issued_in')}><input className="input w-full" maxLength={2} value={form.id_issued_in} onChange={(e) => set('id_issued_in', e.target.value)} /></Field>
                    </Section>
                    <Section title={t('guests.companies.contact')}>
                        <Field label={t('guests.field.email')}><input type="email" className="input w-full" value={form.email} onChange={(e) => set('email', e.target.value)} /></Field>
                        <Field label={t('guests.field.phone')}><input className="input w-full" value={form.phone} onChange={(e) => set('phone', e.target.value)} /></Field>
                        <Field label={t('guests.field.address')}><input className="input w-full" value={form.address} onChange={(e) => set('address', e.target.value)} /></Field>
                        <Field label={t('guests.field.city')}><input className="input w-full" value={form.city} onChange={(e) => set('city', e.target.value)} /></Field>
                        <Field label={t('guests.field.country_iso')}><input className="input w-full" maxLength={2} value={form.country} onChange={(e) => set('country', e.target.value)} /></Field>
                        <Field label={t('guests.company')}>
                            <select className="input w-full" value={form.company_id} onChange={(e) => set('company_id', e.target.value)}>
                                <option value="">—</option>
                                {editing !== 'new' && editing?.company && !companies.data?.data.some((c) => c.id === editing.company!.id) && (
                                    <option value={editing.company.id}>{editing.company.name}</option>
                                )}
                                {companies.data?.data.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                            </select>
                        </Field>
                    </Section>
                    <Section title={t('guests.section.status')}>
                        <div className="flex flex-col gap-2 text-sm text-slate-700">
                            <label className="flex items-center gap-2"><input type="checkbox" checked={form.vip} onChange={(e) => set('vip', e.target.checked)} /> VIP</label>
                            <label className="flex items-center gap-2"><input type="checkbox" checked={form.blacklisted} onChange={(e) => set('blacklisted', e.target.checked)} /> {t('guests.blacklisted')}</label>
                            <label className="flex items-center gap-2"><input type="checkbox" checked={form.marketing_consent} onChange={(e) => set('marketing_consent', e.target.checked)} /> {t('guests.field.marketing')}</label>
                        </div>
                        {form.blacklisted && (
                            <Field label={t('guests.field.blacklist_reason')}><textarea className="input w-full" rows={2} value={form.blacklist_reason} onChange={(e) => set('blacklist_reason', e.target.value)} /></Field>
                        )}
                        <Field label={t('guests.field.notes')}><textarea className="input w-full" rows={2} value={form.notes} onChange={(e) => set('notes', e.target.value)} /></Field>
                    </Section>
                    <div className="flex gap-2 justify-end">
                        <button type="button" className="btn-secondary" onClick={() => setEditing(null)}>{t('common.cancel')}</button>
                        <button className="btn-primary flex items-center gap-1" disabled={saving}>{saving && <Loader2 className="w-4 h-4 animate-spin" />} {t('common.save')}</button>
                    </div>
                </form>
            )}

            {dupFor && (
                <div className="card p-4 space-y-3 border border-amber-200">
                    <div className="flex items-center justify-between">
                        <h3 className="font-semibold text-slate-800">{t('guests.dup.title', { name: fullName(dupFor) })}</h3>
                        <button onClick={() => setDupFor(null)}><X className="w-4 h-4 text-slate-400" /></button>
                    </div>
                    <p className="text-xs text-slate-500">{t('guests.dup.hint', { name: fullName(dupFor) })}</p>
                    {dups.loading && <div className="text-sm text-slate-400">{t('guests.dup.searching')}</div>}
                    {dups.error && <div className="bg-red-50 text-red-700 px-3 py-2 text-sm rounded">{dups.error.message}</div>}
                    {dups.data?.length === 0 && <div className="text-sm text-emerald-700">{t('guests.dup.none')}</div>}
                    {!!dups.data?.length && (
                        <div className="overflow-x-auto">
                            <table className="table-auto w-full text-sm">
                                <thead><tr className="text-left text-slate-500"><th className="px-3 py-1">{t('guests.companies.name')}</th><th className="px-3 py-1">{t('guests.dup.email_phone')}</th><th className="px-3 py-1">{t('guests.dup.id')}</th><th className="px-3 py-1"></th></tr></thead>
                                <tbody>
                                    {dups.data.map((g) => (
                                        <tr key={g.id} className="border-t border-slate-100">
                                            <td className="px-3 py-1">{fullName(g)}</td>
                                            <td className="px-3 py-1">{g.email}<div className="text-xs text-slate-400">{g.phone}</div></td>
                                            <td className="px-3 py-1">{g.id_number}</td>
                                            <td className="px-3 py-1 text-right">
                                                <button className="btn-secondary flex items-center gap-1" disabled={busy === g.id} onClick={() => merge(dupFor, g)}>
                                                    {busy === g.id ? <Loader2 className="w-3 h-3 animate-spin" /> : <Copy className="w-3 h-3" />} {t('guests.dup.merge')}
                                                </button>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </div>
            )}

            <div className="card overflow-x-auto">
                {list.error && <div className="bg-red-50 text-red-700 px-4 py-2 text-sm">{list.error.message}</div>}
                <table className="table-auto w-full text-sm">
                    <thead>
                        <tr className="text-left text-slate-500">
                            <th className="px-4 py-2">{t('guests.col.guest')}</th><th className="px-4 py-2">{t('guests.companies.contact')}</th><th className="px-4 py-2">{t('guests.col.id_doc')}</th>
                            <th className="px-4 py-2">{t('guests.col.nationality')}</th><th className="px-4 py-2">{t('guests.col.flags')}</th><th className="px-4 py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        {list.loading && !list.data && <tr><td colSpan={6} className="px-4 py-6 text-center text-slate-400">{t('common.loading')}</td></tr>}
                        {list.data?.data.length === 0 && <tr><td colSpan={6} className="px-4 py-6 text-center text-slate-400">{t('guests.empty')}</td></tr>}
                        {list.data?.data.map((g) => (
                            <tr key={g.id} className={cn('border-t border-slate-100', g.blacklisted && 'bg-red-50/40')}>
                                <td className="px-4 py-2 font-medium text-slate-800"><Link href={`/guests/${g.id}`} className="text-emerald-700 hover:underline">{fullName(g)}</Link>{g.company && <div className="text-xs font-normal text-slate-400">{g.company.name}</div>}</td>
                                <td className="px-4 py-2">{g.email}<div className="text-xs text-slate-400">{g.phone}</div></td>
                                <td className="px-4 py-2">
                                    {g.id_number ? (
                                        <>
                                            {(() => { const l = ID_TYPES.find(([v]) => v === g.id_type)?.[1]; return l ? t(l) : g.id_type; })()} {g.id_number}
                                            {g.id_expiry && <div className={cn('text-xs', Date.parse(g.id_expiry) < Date.now() ? 'text-red-600' : 'text-slate-400')}>{t('guests.expires', { date: formatDate(g.id_expiry) })}</div>}
                                        </>
                                    ) : <span className="text-amber-700 text-xs">{t('guests.missing')}</span>}
                                </td>
                                <td className="px-4 py-2">{g.nationality}</td>
                                <td className="px-4 py-2 space-x-1 whitespace-nowrap">
                                    {g.vip && <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded bg-amber-50 text-amber-700 text-xs"><Star className="w-3 h-3" /> VIP</span>}
                                    {g.blacklisted && <span title={g.blacklist_reason ?? ''} className="inline-flex items-center gap-1 px-2 py-0.5 rounded bg-red-50 text-red-700 text-xs"><Ban className="w-3 h-3" /> {t('guests.blacklisted')}</span>}
                                </td>
                                <td className="px-4 py-2">
                                    <div className="flex gap-1 justify-end flex-wrap">
                                        <Link href={`/reservations?q=${encodeURIComponent(g.last_name)}`} className="btn-secondary flex items-center gap-1"><CalendarDays className="w-3 h-3" /> {t('guests.stays')}</Link>
                                        <button className="btn-secondary flex items-center gap-1" onClick={() => open(g)}><Pencil className="w-3 h-3" /> {t('common.edit')}</button>
                                        <button className="btn-secondary flex items-center gap-1" onClick={() => { setEditing(null); setDupFor(g); }}><Copy className="w-3 h-3" /> {t('guests.duplicates')}</button>
                                        <button className="btn-secondary flex items-center gap-1" disabled={busy === g.id} onClick={() => exportData(g)}><Download className="w-3 h-3" /> {t('guests.export')}</button>
                                    </div>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
                {list.data && list.data.last_page > 1 && (
                    <div className="flex justify-between items-center px-4 py-2 text-sm text-slate-500">
                        <span>{t('guests.count', { count: list.data.total })}</span>
                        <div className="flex gap-2">
                            <button className="btn-secondary" disabled={page <= 1} onClick={() => setPage(page - 1)}>{t('common.previous')}</button>
                            <span>{page} / {list.data.last_page}</span>
                            <button className="btn-secondary" disabled={page >= list.data.last_page} onClick={() => setPage(page + 1)}>{t('common.next')}</button>
                        </div>
                    </div>
                )}
            </div>
        </div>
    );
}

function Section({ title, children }: { title: string; children: React.ReactNode }) {
    return (
        <fieldset>
            <legend className="text-sm font-semibold text-slate-700 mb-2">{title}</legend>
            <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">{children}</div>
        </fieldset>
    );
}
