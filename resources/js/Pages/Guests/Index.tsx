import Tabs from '@/Components/ui/Tabs';
import FilterBar from '@/Components/ui/FilterBar';
import Modal from '@/Components/ui/Modal';
import AppLayout from '@/Layouts/AppLayout';
import { Head, Link, router } from '@inertiajs/react';
import { Ban, CalendarDays, Copy, Download, ExternalLink, Loader2, Pencil, Plus, Search, Star, Users, Building2, X } from 'lucide-react';
import { useEffect, useState } from 'react';
import DataTable from '@/Components/ui/DataTable';
import EmptyState from '@/Components/ui/EmptyState';
import MasterDetail, { useSplitView } from '@/Components/ui/MasterDetail';
import { toast } from '@/Components/ui/Toast';
import { useQueryParam } from '@/lib/desktop';
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
        >
            <Head title={t('guests.title')} />
            <Tabs label={t('guests.title')} className="mb-4" value={tab} onChange={(k) => setTab(k as typeof tab)}
                tabs={[{ key: 'guests', label: t('guests.tab.guests'), icon: Users }, { key: 'companies', label: t('guests.tab.companies'), icon: Building2 }]} />
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

    const split = useSplitView();
    const [selId, setSelId] = useQueryParam('selected');
    const current = list.data?.data.find((g) => String(g.id) === selId) ?? null;
    const idLabel = (g: Guest) => { const l = ID_TYPES.find(([v]) => v === g.id_type)?.[1]; return l ? t(l) : g.id_type; };
    const flags = (g: Guest) => (g.vip || g.blacklisted) ? (
        <>
            {g.vip && <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded border border-amber-300 bg-amber-50 text-amber-800 text-xs"><Star className="w-3 h-3" aria-hidden="true" /> VIP</span>}
            {g.blacklisted && <span title={g.blacklist_reason ?? ''} className="inline-flex items-center gap-1 px-2 py-0.5 rounded border border-red-300 bg-red-50 text-red-800 text-xs"><Ban className="w-3 h-3" aria-hidden="true" /> {t('guests.blacklisted')}</span>}
        </>
    ) : null;

    // /guests?new=1 (command palette) opens the create form once.
    useEffect(() => {
        const p = new URLSearchParams(window.location.search);
        if (p.get('new') === '1') {
            p.delete('new');
            window.history.replaceState(window.history.state, '', window.location.pathname + (p.toString() ? `?${p}` : ''));
            open('new');
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

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

            <div className="flex justify-end">
                <button type="button" data-primary-action className="btn-primary btn-lg w-full sm:w-auto" onClick={() => open('new')}><Plus className="w-4 h-4" aria-hidden="true" /> {t('guests.new')}</button>
            </div>
            <FilterBar
                activeCount={flag ? 1 : 0}
                onClear={() => { setFlag(''); setPage(1); }}
                search={
                    <div className="relative min-w-0 flex-1 sm:w-80 sm:flex-none">
                        <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-500" aria-hidden="true" />
                        <input type="search" className="input pl-9" aria-label={t('guests.search')} placeholder={t('guests.search')} value={q} onChange={(e) => { setQ(e.target.value); setPage(1); }} />
                    </div>
                }
            >
                <Field label={t('guests.col.flags')}>
                    <select className="select sm:w-48" value={flag} onChange={(e) => { setFlag(e.target.value); setPage(1); }}>
                        <option value="">{t('guests.filter.all')}</option>
                        <option value="vip">{t('guests.filter.vip')}</option>
                        <option value="blacklisted">{t('guests.filter.blacklisted')}</option>
                    </select>
                </Field>
            </FilterBar>

            <Modal open={!!editing} size="xl" onClose={() => setEditing(null)}
                title={editing === 'new' ? t('guests.new') : editing ? t('guests.companies.edit_title', { name: fullName(editing) }) : ''}
                footer={<>
                    <button type="button" className="btn-secondary" onClick={() => setEditing(null)}>{t('common.cancel')}</button>
                    <button type="submit" form="guest-form" className="btn-primary" disabled={saving}>{saving && <Loader2 className="w-4 h-4 animate-spin" aria-hidden="true" />} {t('common.save')}</button>
                </>}>
                {editing && (
                <form id="guest-form" onSubmit={save} className="space-y-4">
                    <Section title={t('guests.section.identity')}>
                        <Field label={t('guests.field.title')}><input className="input w-full" aria-label={t('guests.field.title_ph')} placeholder={t('guests.field.title_ph')} value={form.title} onChange={(e) => set('title', e.target.value)} /></Field>
                        <Field label={t('guests.field.first_name_req')}><input className="input w-full" required value={form.first_name} onChange={(e) => set('first_name', e.target.value)} /></Field>
                        <Field label={t('guests.field.last_name_req')}><input className="input w-full" required value={form.last_name} onChange={(e) => set('last_name', e.target.value)} /></Field>
                        <Field label={t('guests.field.gender')}>
                            <select className="input w-full" value={form.gender} onChange={(e) => set('gender', e.target.value)}>
                                <option value="">—</option><option value="M">{t('guests.gender.m')}</option><option value="F">{t('guests.gender.f')}</option><option value="X">{t('guests.gender.x')}</option>
                            </select>
                        </Field>
                        <Field label={t('guests.field.dob')}><input type="date" className="input w-full" value={form.date_of_birth} onChange={(e) => set('date_of_birth', e.target.value)} /></Field>
                        <Field label={t('guests.field.nationality_iso')}><input className="input w-full" maxLength={2} aria-label="CM" placeholder="CM" value={form.nationality} onChange={(e) => set('nationality', e.target.value)} /></Field>
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
                            <label className="min-h-11 flex items-center gap-2"><input type="checkbox" checked={form.vip} onChange={(e) => set('vip', e.target.checked)} /> VIP</label>
                            <label className="min-h-11 flex items-center gap-2"><input type="checkbox" checked={form.blacklisted} onChange={(e) => set('blacklisted', e.target.checked)} /> {t('guests.blacklisted')}</label>
                            <label className="min-h-11 flex items-center gap-2"><input type="checkbox" checked={form.marketing_consent} onChange={(e) => set('marketing_consent', e.target.checked)} /> {t('guests.field.marketing')}</label>
                        </div>
                        {form.blacklisted && (
                            <Field label={t('guests.field.blacklist_reason')}><textarea className="input w-full" rows={2} value={form.blacklist_reason} onChange={(e) => set('blacklist_reason', e.target.value)} /></Field>
                        )}
                        <Field label={t('guests.field.notes')}><textarea className="input w-full" rows={2} value={form.notes} onChange={(e) => set('notes', e.target.value)} /></Field>
                    </Section>
                </form>
                )}
            </Modal>

            {dupFor && (
                <div className="card p-4 space-y-3 border border-amber-300">
                    <div className="flex items-center justify-between">
                        <h3 className="font-semibold text-slate-800">{t('guests.dup.title', { name: fullName(dupFor) })}</h3>
                        <button type="button" className="btn-ghost btn-icon" aria-label={t('ui.modal.close')} onClick={() => setDupFor(null)}><X className="w-4 h-4" aria-hidden="true" /></button>
                    </div>
                    <p className="text-sm text-slate-600">{t('guests.dup.hint', { name: fullName(dupFor) })}</p>
                    {dups.loading && <div className="text-sm text-slate-500">{t('guests.dup.searching')}</div>}
                    {dups.error && <div className="bg-red-50 text-red-700 px-3 py-2 text-sm rounded">{dups.error.message}</div>}
                    {dups.data?.length === 0 && <div className="text-sm text-emerald-700">{t('guests.dup.none')}</div>}
                    {!!dups.data?.length && (
                        <div className="overflow-x-auto">
                            <table className="table-auto">
                                <thead><tr><th className="px-3 py-1">{t('guests.companies.name')}</th><th className="px-3 py-1">{t('guests.dup.email_phone')}</th><th className="px-3 py-1">{t('guests.dup.id')}</th><th className="px-3 py-1"></th></tr></thead>
                                <tbody>
                                    {dups.data.map((g) => (
                                        <tr key={g.id}>
                                            <td className="px-3 py-1">{fullName(g)}</td>
                                            <td className="px-3 py-1">{g.email}<div className="text-xs text-slate-500">{g.phone}</div></td>
                                            <td className="px-3 py-1">{g.id_number}</td>
                                            <td className="px-3 py-1 text-right">
                                                <button type="button" className="btn-secondary btn-sm" disabled={busy === g.id} onClick={() => merge(dupFor, g)}>
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

            <MasterDetail
                open={!!current}
                title={current ? fullName(current) : ''}
                onClose={() => setSelId(null)}
                detail={current && (
                    <div className="space-y-4">
                        <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-2 text-sm">
                            <dt className="text-slate-500">{t('guests.field.email')}</dt><dd className="break-all">{current.email ?? '-'}</dd>
                            <dt className="text-slate-500">{t('guests.field.phone')}</dt><dd>{current.phone ?? '-'}</dd>
                            <dt className="text-slate-500">{t('desktop.guest.id_doc')}</dt><dd>{current.id_number ? `${idLabel(current)} ${current.id_number}` : t('guests.missing')}</dd>
                            <dt className="text-slate-500">{t('guests.col.nationality')}</dt><dd>{current.nationality ?? '-'}</dd>
                            <dt className="text-slate-500">{t('guests.company')}</dt><dd>{current.company?.name ?? '-'}</dd>
                            <dt className="text-slate-500">{t('guests.col.flags')}</dt>
                            <dd className="space-x-1">{flags(current) ?? '-'}</dd>
                        </dl>
                        <div className="btn-row">
                            <button type="button" className="btn-primary btn-sm" onClick={() => open(current)}><Pencil className="w-4 h-4" aria-hidden="true" /> {t('common.edit')}</button>
                            <Link href={`/reservations?q=${encodeURIComponent(current.last_name)}`} className="btn-secondary btn-sm"><CalendarDays className="w-4 h-4" aria-hidden="true" /> {t('guests.stays')}</Link>
                            <Link href={`/guests/${current.id}`} className="btn-secondary btn-sm"><ExternalLink className="w-4 h-4" aria-hidden="true" /> {t('desktop.detail.open_page')}</Link>
                        </div>
                        <p className="text-sm text-slate-500">{t('desktop.detail.hint')}</p>
                    </div>
                )}
            >
            <div className="space-y-3">
                <DataTable
                    caption={t('guests.title')}
                    tableKey="guests"
                    loading={list.loading && !list.data}
                    error={list.error && !list.data ? list.error.message : undefined}
                    rows={list.data?.data ?? []}
                    rowKey={(g) => g.id}
                    rowLabel={fullName}
                    activeKey={current?.id ?? null}
                    onRowClick={(g) => (split ? setSelId(String(g.id)) : router.visit(`/guests/${g.id}`))}
                    selectable
                    bulkActions={({ rows, clear }) => (
                        <button type="button" className="btn-secondary btn-sm" onClick={() => {
                            const mails = rows.map((g) => g.email).filter(Boolean) as string[];
                            navigator.clipboard?.writeText(mails.join('; ')).then(() => toast.success(t('desktop.copy.done', { count: mails.length }))).catch(() => toast.error(t('desktop.copy.failed')));
                            clear();
                        }}><Copy className="w-4 h-4" aria-hidden="true" /> {t('desktop.copy.emails')}</button>
                    )}
                    rowActions={(g) => [
                        { key: 'open', label: t('desktop.detail.open_page'), icon: ExternalLink, onSelect: (x) => router.visit(`/guests/${x.id}`) },
                        { key: 'edit', label: t('common.edit'), icon: Pencil, onSelect: (x) => open(x) },
                        { key: 'stays', label: t('guests.stays'), icon: CalendarDays, onSelect: (x) => router.visit(`/reservations?q=${encodeURIComponent(x.last_name)}`) },
                        { key: 'dups', label: t('guests.duplicates'), icon: Copy, onSelect: (x) => { setEditing(null); setDupFor(x); } },
                        { key: 'export', label: t('guests.export'), icon: Download, disabled: busy === g.id, onSelect: (x) => exportData(x) },
                    ]}
                    empty={<EmptyState icon={Users} title={t('guests.empty')} />}
                    columns={[
                        { key: 'guest', header: t('guests.col.guest'), sortValue: (g) => `${g.last_name} ${g.first_name}`, cell: (g) => (<><Link href={`/guests/${g.id}`} className="inline-flex min-h-11 items-center font-medium text-navy-600 underline underline-offset-2">{fullName(g)}</Link>{g.company && <div className="text-xs font-normal text-slate-500">{g.company.name}</div>}</>) },
                        { key: 'contact', header: t('guests.companies.contact'), sortValue: (g) => g.email ?? g.phone ?? '', cell: (g) => <>{g.email}<div className="text-xs text-slate-500">{g.phone}</div></> },
                        { key: 'id', header: t('guests.col.id_doc'), cell: (g) => g.id_number ? (
                            <>
                                {idLabel(g)} {g.id_number}
                                {g.id_expiry && <div className={cn('text-xs', Date.parse(g.id_expiry) < Date.now() ? 'text-red-700' : 'text-slate-600')}>{t('guests.expires', { date: formatDate(g.id_expiry) })}</div>}
                            </>
                        ) : <span className="text-amber-800 text-sm">{t('guests.missing')}</span> },
                        { key: 'nat', header: t('guests.col.nationality'), sortValue: (g) => g.nationality ?? '', cell: (g) => g.nationality },
                        { key: 'flags', header: t('guests.col.flags'), cell: (g) => <span className="space-x-1 whitespace-nowrap">{flags(g)}</span> },
                        { key: 'act', header: '', hideLabel: true, align: 'right', required: true, cell: (g) => (
                            <div className="flex flex-wrap justify-end gap-1">
                                <Link href={`/reservations?q=${encodeURIComponent(g.last_name)}`} className="btn-secondary btn-sm"><CalendarDays className="w-4 h-4" aria-hidden="true" /> {t('guests.stays')}</Link>
                                <button type="button" className="btn-secondary btn-sm" onClick={() => open(g)}><Pencil className="w-4 h-4" aria-hidden="true" /> {t('common.edit')}</button>
                            </div>
                        ) },
                    ]}
                />
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
            </MasterDetail>
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
