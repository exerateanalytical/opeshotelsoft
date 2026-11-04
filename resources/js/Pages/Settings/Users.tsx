import AppLayout from '@/Layouts/AppLayout';
import { Head, usePage } from '@inertiajs/react';
import { Loader2, Pencil, Plus, Search, X } from 'lucide-react';
import { useMemo, useState } from 'react';
import { api, useApi } from '@/lib/api';
import { cn } from '@/lib/utils';
import { useT } from '@/lib/i18n';
import Modal from '@/Components/ui/Modal';
import EmptyState from '@/Components/ui/EmptyState';
import ExportButtons from '@/Components/ExportButtons';
import { Banner, errorText, Field, Forbidden, Paginated } from './shared';
import ReasonConfirmModal from '@/Components/gaph/ReasonConfirmModal';
import TemporaryPasswordModal from '@/Components/gaph/TemporaryPasswordModal';

interface HotelRef { id: number; code: string; name: string }
interface User {
    id: number;
    name: string;
    email: string;
    phone: string | null;
    status: string;
    all_hotels: boolean;
    roles: { id: number; name: string }[];
    hotels: HotelRef[];
    mfa_confirmed_at?: string | null;
}
interface Role { id: number; name: string; permissions: { id: number; name: string }[] }

interface Draft { name: string; email: string; phone: string; password: string; role: string; all_hotels: boolean; hotel_ids: number[]; status: string }
const empty: Draft = { name: '', email: '', phone: '', password: '', role: '', all_hotels: false, hotel_ids: [], status: 'active' };

export default function SettingsUsers() {
    const t = useT();
    const users = useApi<Paginated<User>>('users');
    const roles = useApi<Role[]>('roles');
    const hotels = useApi<Paginated<HotelRef>>('hotels');
    const [q, setQ] = useState('');
    const [roleFilter, setRoleFilter] = useState('');
    const [hotelFilter, setHotelFilter] = useState('');
    const [editing, setEditing] = useState<User | 'new' | null>(null);
    const [draft, setDraft] = useState<Draft>(empty);
    const [busy, setBusy] = useState(false);
    const [message, setMessage] = useState<{ ok: boolean; text: string } | null>(null);
    const [toggling, setToggling] = useState<User | null>(null);
    const me = (usePage().props as { auth?: { user?: { id: number } | null } }).auth?.user?.id;
    const [resetting, setResetting] = useState<{ user: User; kind: 'password' | 'two-factor' } | null>(null);
    const [resetError, setResetError] = useState<string | null>(null);
    const [temp, setTemp] = useState<{ name: string; password: string } | null>(null);

    async function runReset(reason: string) {
        if (!resetting) return;
        const { user: u, kind } = resetting;
        setBusy(true);
        setResetError(null);
        setMessage(null);
        try {
            const res = await api<{ temporary_password?: string }>(`users/${u.id}/${kind}-reset`, { method: 'POST', body: JSON.stringify({ reason }) });
            setResetting(null);
            if (kind === 'password' && res?.temporary_password) setTemp({ name: u.name, password: res.temporary_password });
            setMessage({ ok: true, text: t(kind === 'password' ? 'gaph.password.done_banner' : 'gaph.mfa.done_banner', { name: u.name }) });
            users.reload();
        } catch (err) {
            const status = (err as { problem?: { status?: number } }).problem?.status;
            setResetError(status === 429 ? t('gaph.rate_limited') : errorText(err));
        } finally {
            setBusy(false);
        }
    }

    async function toggleStatus(u: User) {
        const next = u.status === 'active' ? 'suspended' : 'active';
        setBusy(true);
        setMessage(null);
        try {
            await api(`users/${u.id}`, { method: 'PATCH', body: JSON.stringify({ status: next }) });
            setMessage({ ok: true, text: t(next === 'suspended' ? 'gapf.users.deactivated' : 'gapf.users.reactivated', { name: u.name }) });
            setToggling(null);
            users.reload();
        } catch (err) {
            setMessage({ ok: false, text: errorText(err) });
            setToggling(null);
        } finally {
            setBusy(false);
        }
    }

    const forbidden = users.error?.problem.status === 403;
    const hotelList = hotels.data?.data ?? [];

    const filtered = useMemo(() => (users.data?.data ?? []).filter((u) => {
        const s = q.toLowerCase();
        if (s && !u.name.toLowerCase().includes(s) && !u.email.toLowerCase().includes(s)) return false;
        if (roleFilter && !u.roles.some((r) => r.name === roleFilter)) return false;
        if (hotelFilter && !u.all_hotels && !u.hotels.some((h) => String(h.id) === hotelFilter)) return false;
        return true;
    }), [users.data, q, roleFilter, hotelFilter]);

    function open(u: User | 'new') {
        setMessage(null);
        setEditing(u);
        setDraft(u === 'new' ? empty : {
            ...empty, name: u.name, email: u.email, phone: u.phone ?? '', role: u.roles[0]?.name ?? '',
            all_hotels: u.all_hotels, hotel_ids: u.hotels.map((h) => h.id), status: u.status,
        });
    }

    async function save(e: React.FormEvent) {
        e.preventDefault();
        if (!editing) return;
        setBusy(true);
        setMessage(null);
        try {
            const scope = { all_hotels: draft.all_hotels, hotel_ids: draft.all_hotels ? [] : draft.hotel_ids };
            if (editing === 'new') {
                await api('users', { method: 'POST', body: JSON.stringify({
                    name: draft.name, email: draft.email, phone: draft.phone || null,
                    password: draft.password || null, roles: [draft.role], ...scope,
                }) });
                setMessage({ ok: true, text: t('settings.users.created', { email: draft.email }) });
            } else {
                await api(`users/${editing.id}`, { method: 'PATCH', body: JSON.stringify({
                    name: draft.name, status: draft.status, roles: draft.role ? [draft.role] : [], ...scope,
                }) });
                setMessage({ ok: true, text: t('settings.users.updated', { name: draft.name }) });
            }
            setEditing(null);
            users.reload();
        } catch (err) {
            setMessage({ ok: false, text: errorText(err) });
        } finally {
            setBusy(false);
        }
    }

    const toggleHotel = (id: number) => setDraft((d) => ({
        ...d, hotel_ids: d.hotel_ids.includes(id) ? d.hotel_ids.filter((x) => x !== id) : [...d.hotel_ids, id],
    }));

    return (
        <AppLayout
            title={t('settings.users.title')}
            subtitle={t('settings.users.subtitle')}
            actions={!forbidden && <button className="btn-primary" onClick={() => open('new')}><Plus className="w-4 h-4" /> {t('settings.users.new')}</button>}
        >
            <Head title={t('settings.users.title')} />
            {forbidden ? <Forbidden permission="users.manage" /> : (
                <>
                    {message && <Banner {...message} />}
                    {users.error && <Banner ok={false} text={users.error.message} />}

                    {editing && (
                        <form onSubmit={save} className="card px-5 py-4 mb-6 space-y-4">
                            <div className="flex items-center">
                                <h3 className="font-semibold text-slate-800">{editing === 'new' ? t('settings.users.new') : t('settings.users.edit', { name: editing.name })}</h3>
                                <button type="button" aria-label={t('common.cancel')} className="hit-44 ml-auto text-slate-500 hover:text-slate-600" onClick={() => setEditing(null)}><X className="w-5 h-5" /></button>
                            </div>
                            <div className="grid sm:grid-cols-2 gap-3">
                                <Field label={t('profile.name')}><input className="input w-full" required value={draft.name} onChange={(e) => setDraft({ ...draft, name: e.target.value })} /></Field>
                                <Field label={t('profile.email')}>
                                    <input className="input w-full" type="email" required disabled={editing !== 'new'} value={draft.email} onChange={(e) => setDraft({ ...draft, email: e.target.value })} />
                                </Field>
                                {editing === 'new' && (
                                    <>
                                        <Field label={t('settings.users.phone')}><input className="input w-full" value={draft.phone} onChange={(e) => setDraft({ ...draft, phone: e.target.value })} /></Field>
                                        <Field label={t('settings.users.temp_password')}>
                                            <input className="input w-full" type="password" minLength={10} autoComplete="new-password" value={draft.password} onChange={(e) => setDraft({ ...draft, password: e.target.value })} />
                                        </Field>
                                    </>
                                )}
                                <Field label={t('settings.users.role')}>
                                    <select className="select w-full" required value={draft.role} onChange={(e) => setDraft({ ...draft, role: e.target.value })}>
                                        <option value="">{t('settings.users.choose_role')}</option>
                                        {(roles.data ?? []).map((r) => <option key={r.id} value={r.name}>{r.name}</option>)}
                                    </select>
                                </Field>
                                {editing !== 'new' && (
                                    <Field label={t('settings.users.status')}>
                                        <select className="select w-full" value={draft.status} onChange={(e) => setDraft({ ...draft, status: e.target.value })}>
                                            <option value="active">{t('settings.users.active')}</option>
                                            <option value="suspended">{t('settings.users.suspended')}</option>
                                        </select>
                                    </Field>
                                )}
                            </div>
                            <div>
                                <label className="flex items-center gap-2 text-sm">
                                    <input type="checkbox" checked={draft.all_hotels} onChange={(e) => setDraft({ ...draft, all_hotels: e.target.checked })} />
                                    {t('settings.users.all_hotels_access')}
                                </label>
                                {!draft.all_hotels && (
                                    <div className="flex flex-wrap gap-2 mt-2">
                                        {hotelList.map((h) => (
                                            <button type="button" key={h.id} onClick={() => toggleHotel(h.id)}
                                                className={cn('hit-44 text-xs px-3 py-1 rounded-full border', draft.hotel_ids.includes(h.id) ? 'bg-indigo-600 text-white border-indigo-600' : 'border-slate-300 text-slate-600')}>
                                                {h.code} · {h.name}
                                            </button>
                                        ))}
                                    </div>
                                )}
                            </div>
                            <div className="flex gap-2">
                                <button className="btn-primary" disabled={busy}>{busy && <Loader2 className="w-4 h-4 animate-spin" />} Save</button>
                                <button type="button" className="btn-secondary" onClick={() => setEditing(null)}>{t('common.cancel')}</button>
                            </div>
                        </form>
                    )}

                    <div className="grid lg:grid-cols-3 gap-6 [&>*]:min-w-0">
                        <div className="lg:col-span-2 card overflow-x-auto">
                            <div className="flex flex-wrap gap-2 px-5 py-4 border-b border-slate-100">
                                <div className="relative flex-1 min-w-[10rem]">
                                    <Search className="w-4 h-4 absolute left-2.5 top-2.5 text-slate-500" />
                                    <input className="input w-full pl-8" placeholder={t('settings.users.search')} aria-label={t('settings.users.search')} value={q} onChange={(e) => setQ(e.target.value)} />
                                </div>
                                <select className="select" aria-label={t('ui2.users.filter_role')} value={roleFilter} onChange={(e) => setRoleFilter(e.target.value)}>
                                    <option value="">{t('settings.users.all_roles')}</option>
                                    {(roles.data ?? []).map((r) => <option key={r.id} value={r.name}>{r.name}</option>)}
                                </select>
                                <select className="select" aria-label={t('ui2.users.filter_hotel')} value={hotelFilter} onChange={(e) => setHotelFilter(e.target.value)}>
                                    <option value="">{t('settings.users.all_hotels')}</option>
                                    {hotelList.map((h) => <option key={h.id} value={h.id}>{h.code}</option>)}
                                </select>
                            </div>
                            <div className="px-5 py-3 border-b border-slate-100"><ExportButtons report="staff-users" /></div>
                            <table className="table-auto w-full">
                                <thead><tr><th>{t('settings.users.user')}</th><th>{t('settings.users.role')}</th><th>{t('settings.users.hotels')}</th><th>{t('settings.users.status')}</th><th></th></tr></thead>
                                <tbody>
                                    {users.loading && !users.data && <tr><td colSpan={5}><Loader2 className="w-5 h-5 mx-auto my-8 animate-spin text-slate-500" /></td></tr>}
                                    {users.data && filtered.length === 0 && <tr><td colSpan={5}><EmptyState title={t('settings.users.no_match')} action={(users.data?.data ?? []).length === 0 ? <button type="button" className="btn-primary" onClick={() => open('new')}><Plus className="w-4 h-4" /> {t('settings.users.new')}</button> : undefined} /></td></tr>}
                                    {filtered.map((u) => (
                                        <tr key={u.id}>
                                            <td><div className="text-sm font-medium text-slate-800">{u.name}</div><div className="text-xs text-slate-500">{u.email}</div></td>
                                            <td className="text-sm">{u.roles.map((r) => r.name).join(', ') || '—'}</td>
                                            <td className="text-xs">{u.all_hotels ? t('settings.users.all_hotels') : u.hotels.map((h) => h.code).join(', ') || '—'}</td>
                                            <td><span className={cn('text-xs px-2 py-0.5 rounded', u.status === 'active' ? 'bg-emerald-50 text-emerald-700' : 'bg-red-50 text-red-700')}>{u.status}</span></td>
                                            <td className="text-right"><button title={t('common.edit')} aria-label={t('common.edit')} className="hit-44 text-slate-500 hover:text-indigo-600" onClick={() => open(u)}><Pencil className="w-4 h-4" /></button>
                                                <button type="button" className="btn-ghost btn-sm" onClick={() => setToggling(u)}>{t(u.status === 'active' ? 'gapf.users.deactivate' : 'gapf.users.reactivate')}</button>
                                                {u.id !== me && (
                                                    <>
                                                        <button type="button" className="btn-ghost btn-sm" onClick={() => { setResetError(null); setResetting({ user: u, kind: 'password' }); }}>{t('gaph.password.action')}</button>
                                                        {u.mfa_confirmed_at && <button type="button" className="btn-ghost btn-sm" onClick={() => { setResetError(null); setResetting({ user: u, kind: 'two-factor' }); }}>{t('gaph.mfa.action')}</button>}
                                                    </>
                                                )}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>

                        <div className="card px-5 py-4">
                            <h3 className="font-semibold text-slate-800 mb-3">{t('settings.users.roles')}</h3>
                            {roles.error && <Banner ok={false} text={roles.error.message} />}
                            {!roles.data && !roles.error && <Loader2 className="w-5 h-5 mx-auto animate-spin text-slate-500" />}
                            <div className="space-y-2">
                                {(roles.data ?? []).map((r) => (
                                    <details key={r.id} className="border border-slate-100 rounded-lg px-3 py-2">
                                        <summary className="cursor-pointer break-words text-sm font-medium text-slate-700">
                                            {r.name} <span className="text-xs text-slate-500">({r.permissions.length})</span>
                                        </summary>
                                        <div className="flex flex-wrap gap-1 mt-2">
                                            {r.permissions.map((p) => <span key={p.id} className="max-w-full break-all text-xs font-mono px-2 py-0.5 rounded bg-slate-100 text-slate-600">{p.name}</span>)}
                                        </div>
                                    </details>
                                ))}
                            </div>
                        </div>
                    </div>
                    <Modal open={toggling !== null} onClose={() => setToggling(null)} title={toggling?.status === 'active' ? t('gapf.users.deactivate_title') : t('gapf.users.reactivate_title')}
                        footer={<button type="button" className={toggling?.status === 'active' ? 'btn-danger' : 'btn-primary'} disabled={busy} onClick={() => toggling && toggleStatus(toggling)}>{t(toggling?.status === 'active' ? 'gapf.users.deactivate' : 'gapf.users.reactivate')}</button>}>
                        <p className="text-sm">{t(toggling?.status === 'active' ? 'gapf.users.deactivate_confirm' : 'gapf.users.reactivate_confirm', { name: toggling?.name ?? '' })}</p>
                    </Modal>
                    <ReasonConfirmModal open={resetting !== null} danger busy={busy} error={resetError}
                        title={t(resetting?.kind === 'two-factor' ? 'gaph.mfa.title' : 'gaph.password.title', { name: resetting?.user.name ?? '' })}
                        message={t(resetting?.kind === 'two-factor' ? 'gaph.mfa.confirm' : 'gaph.password.confirm', { name: resetting?.user.name ?? '' })}
                        confirmLabel={t(resetting?.kind === 'two-factor' ? 'gaph.mfa.action' : 'gaph.password.action')}
                        onClose={() => setResetting(null)} onConfirm={runReset} />
                    <TemporaryPasswordModal name={temp?.name ?? ''} password={temp?.password ?? null} onClose={() => setTemp(null)} />
                </>
            )}
        </AppLayout>
    );
}
