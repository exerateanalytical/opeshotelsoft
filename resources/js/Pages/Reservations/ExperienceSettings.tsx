import AppLayout from '@/Layouts/AppLayout';
import { Head, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { Pencil, Plus, Star } from 'lucide-react';
import { api, ApiError, useApi } from '@/lib/api';
import { useT } from '@/lib/i18n';
import Button from '@/Components/ui/Button';
import Field from '@/Components/ui/Field';
import Modal from '@/Components/ui/Modal';
import Tabs from '@/Components/ui/Tabs';
import EmptyState from '@/Components/ui/EmptyState';
import { toast } from '@/Components/ui/Toast';

interface Settings {
    deposit_deadline_hours: number; deposit_reminder_hours: number; guest_modify_cutoff_hours: number;
    guest_modify: boolean; early_checkin_enabled: boolean; late_checkout_enabled: boolean; auto_release: boolean; auto_no_show: boolean; no_show_fee: boolean;
}
interface Svc { id: number; code: string; name: string; name_en: string | null; price: number; charge_code: string; active: boolean }
interface Rule { id: number; kind: 'early_checkin' | 'late'; after_hour: number; charge_code: string; fee_type: 'percent_of_night' | 'fixed'; value: number; label: string | null; active: boolean }
interface Review { id: number; rating: number; comment: string | null; submitted_at: string; stay: { number: string; arrival: string; departure: string; guest: string } | null }
interface Paged<T> { data: T[]; current_page: number; last_page: number }

const money = (n: number) => new Intl.NumberFormat('fr-FR').format(n);
const msg = (e: unknown) => (e instanceof ApiError ? e.message : String(e));
const fail = (e: unknown) => toast.error(msg(e));

function Switch({ label, hint, checked, disabled, onChange }: { label: string; hint?: string; checked: boolean; disabled: boolean; onChange: (v: boolean) => void }) {
    return (
        <label className="flex min-h-[44px] items-start gap-3 py-2">
            <input type="checkbox" className="mt-1 h-5 w-5 shrink-0" checked={checked} disabled={disabled} onChange={(e) => onChange(e.target.checked)} />
            <span className="min-w-0">
                <span className="block text-base text-slate-900">{label}</span>
                {hint && <span className="field-hint">{hint}</span>}
            </span>
        </label>
    );
}

function SettingsTab({ canEdit }: { canEdit: boolean }) {
    const t = useT();
    const q = useApi<Settings>('experience/settings');
    const [f, setF] = useState<Settings | null>(null);
    const [busy, setBusy] = useState(false);
    useEffect(() => { if (q.data) setF(q.data); }, [q.data]);
    if (q.error) return <p role="alert" className="field-error">{t('experience2.loading_failed')}</p>;
    if (!f) return <div className="card p-4">…</div>;
    const set = <K extends keyof Settings>(k: K, v: Settings[K]) => setF({ ...f, [k]: v });
    const num = (k: 'deposit_deadline_hours' | 'deposit_reminder_hours' | 'guest_modify_cutoff_hours', hint?: string) => (
        <Field label={t(`experience2.settings.${k}`)} hint={hint}>
            <input className="input w-full" type="number" min={k === 'guest_modify_cutoff_hours' ? 0 : 1} max={720} value={f[k]} disabled={!canEdit} onChange={(e) => set(k, Number(e.target.value))} />
        </Field>
    );
    const save = async (e: React.FormEvent) => {
        e.preventDefault();
        setBusy(true);
        try { await api('experience/settings', { method: 'PUT', body: JSON.stringify(f) }); toast.success(t('experience2.saved')); q.reload(); } catch (x) { fail(x); } finally { setBusy(false); }
    };
    const sw = (k: keyof Settings, hint?: string) => <Switch label={t(`experience2.settings.${k}`)} hint={hint} checked={!!f[k]} disabled={!canEdit} onChange={(v) => set(k, v as never)} />;
    return (
        <form onSubmit={save} className="card space-y-4 p-4 md:p-5">
            {!canEdit && <p className="field-hint">{t('experience2.read_only')}</p>}
            <div className="grid gap-4 md:grid-cols-3">
                {num('deposit_deadline_hours', t('experience2.settings.deposit_deadline_hours_hint'))}
                {num('deposit_reminder_hours')}
                {num('guest_modify_cutoff_hours')}
            </div>
            <div className="divide-y divide-slate-200">
                {sw('guest_modify')}
                {sw('early_checkin_enabled')}
                {sw('late_checkout_enabled')}
                {sw('auto_release')}
                {sw('auto_no_show')}
                {sw('no_show_fee', t('experience2.settings.no_show_fee_hint'))}
            </div>
            {canEdit && <div className="btn-row"><Button type="submit" loading={busy}>{t('experience2.save')}</Button></div>}
        </form>
    );
}

function ServicesTab({ canEdit }: { canEdit: boolean }) {
    const t = useT();
    const q = useApi<{ data: Svc[] }>('experience/services');
    const [edit, setEdit] = useState<Partial<Svc> | null>(null);
    const [busy, setBusy] = useState(false);
    const save = async (e: React.FormEvent) => {
        e.preventDefault();
        if (!edit) return;
        setBusy(true);
        const body = { code: edit.code, name: edit.name, name_en: edit.name_en || null, price: Number(edit.price ?? 0), charge_code: edit.charge_code, active: edit.active ?? true };
        try {
            await api(edit.id ? `experience/services/${edit.id}` : 'experience/services', { method: edit.id ? 'PATCH' : 'POST', body: JSON.stringify(body) });
            toast.success(t('experience2.saved')); setEdit(null); q.reload();
        } catch (x) { fail(x); } finally { setBusy(false); }
    };
    const toggle = async (s: Svc) => {
        try { await api(`experience/services/${s.id}`, { method: 'PATCH', body: JSON.stringify({ active: !s.active }) }); q.reload(); } catch (x) { fail(x); }
    };
    const rows = q.data?.data ?? [];
    return (
        <div className="space-y-3">
            {canEdit && <div className="btn-row"><Button icon={Plus} onClick={() => setEdit({ active: true, price: 0, charge_code: '' })}>{t('experience2.services.new')}</Button></div>}
            {q.error ? <p role="alert" className="field-error">{t('experience2.loading_failed')}</p> : rows.length === 0 && !q.loading ? (
                <EmptyState title={t('experience2.services.none')} />
            ) : (
                <div className="table-wrap card">
                    <table className="table-std table-cards">
                        <thead><tr>
                            <th>{t('experience2.services.code')}</th><th>{t('experience2.services.name_fr')}</th><th>{t('experience2.services.name_en')}</th>
                            <th>{t('experience2.services.price')}</th><th>{t('experience2.services.charge_code')}</th><th>{t('experience2.services.status')}</th>{canEdit && <th />}
                        </tr></thead>
                        <tbody>
                            {rows.map((s) => (
                                <tr key={s.id}>
                                    <td data-label={t('experience2.services.code')} className="font-medium">{s.code}</td>
                                    <td data-label={t('experience2.services.name_fr')}>{s.name}</td>
                                    <td data-label={t('experience2.services.name_en')}>{s.name_en ?? '-'}</td>
                                    <td data-label={t('experience2.services.price')}>{money(s.price)}</td>
                                    <td data-label={t('experience2.services.charge_code')}>{s.charge_code}</td>
                                    <td data-label={t('experience2.services.status')}><span className={s.active ? 'badge-green' : 'badge-slate'}>{s.active ? t('experience2.active') : t('experience2.inactive')}</span></td>
                                    {canEdit && (
                                        <td className="btn-row">
                                            <Button variant="secondary" size="sm" icon={Pencil} onClick={() => setEdit(s)}>{t('experience2.edit')}</Button>
                                            <Button variant="ghost" size="sm" onClick={() => toggle(s)}>{s.active ? t('experience2.deactivate') : t('experience2.activate')}</Button>
                                        </td>
                                    )}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
            <Modal open={!!edit} onClose={() => setEdit(null)} title={edit?.id ? t('experience2.services.edit') : t('experience2.services.new')}
                footer={<><Button variant="secondary" onClick={() => setEdit(null)}>{t('experience2.cancel')}</Button><Button type="submit" form="svc-form" loading={busy}>{t('experience2.save')}</Button></>}>
                {edit && (
                    <form id="svc-form" onSubmit={save} className="space-y-3">
                        <Field label={t('experience2.services.code')} required><input className="input w-full" maxLength={24} value={edit.code ?? ''} disabled={!!edit.id} onChange={(e) => setEdit({ ...edit, code: e.target.value.toUpperCase() })} /></Field>
                        <Field label={t('experience2.services.name_fr')} required><input className="input w-full" maxLength={120} value={edit.name ?? ''} onChange={(e) => setEdit({ ...edit, name: e.target.value })} /></Field>
                        <Field label={t('experience2.services.name_en')}><input className="input w-full" maxLength={120} value={edit.name_en ?? ''} onChange={(e) => setEdit({ ...edit, name_en: e.target.value })} /></Field>
                        <Field label={t('experience2.services.price')} required><input className="input w-full" type="number" min={0} value={edit.price ?? 0} onChange={(e) => setEdit({ ...edit, price: Number(e.target.value) })} /></Field>
                        <Field label={t('experience2.services.charge_code')} required><input className="input w-full" maxLength={16} value={edit.charge_code ?? ''} onChange={(e) => setEdit({ ...edit, charge_code: e.target.value.toUpperCase() })} /></Field>
                    </form>
                )}
            </Modal>
        </div>
    );
}

function FeesTab({ canEdit }: { canEdit: boolean }) {
    const t = useT();
    const q = useApi<{ data: Rule[] }>('experience/fee-rules');
    const [edit, setEdit] = useState<Partial<Rule> | null>(null);
    const [busy, setBusy] = useState(false);
    const save = async (e: React.FormEvent) => {
        e.preventDefault();
        if (!edit) return;
        setBusy(true);
        const body = { kind: edit.kind, after_hour: Number(edit.after_hour ?? 0), charge_code: edit.charge_code, fee_type: edit.fee_type, value: Number(edit.value ?? 0), label: edit.label || null, active: edit.active ?? true };
        try {
            await api(edit.id ? `experience/fee-rules/${edit.id}` : 'experience/fee-rules', { method: edit.id ? 'PATCH' : 'POST', body: JSON.stringify(body) });
            toast.success(t('experience2.saved')); setEdit(null); q.reload();
        } catch (x) { fail(x); } finally { setBusy(false); }
    };
    const toggle = async (r: Rule) => {
        try { await api(`experience/fee-rules/${r.id}`, { method: 'PATCH', body: JSON.stringify({ active: !r.active }) }); q.reload(); } catch (x) { fail(x); }
    };
    const rows = q.data?.data ?? [];
    const band = (r: Rule) => t(r.kind === 'early_checkin' ? 'experience2.fees.early_band' : 'experience2.fees.late_band', { hour: r.after_hour });
    const amount = (r: Rule) => (r.fee_type === 'fixed' ? money(r.value) : `${r.value} %`);
    return (
        <div className="space-y-3">
            <p className="field-hint">{t('experience2.fees.intro')}</p>
            {canEdit && <div className="btn-row"><Button icon={Plus} onClick={() => setEdit({ kind: 'early_checkin', fee_type: 'percent_of_night', after_hour: 10, charge_code: 'ROOM', value: 30, active: true })}>{t('experience2.fees.new')}</Button></div>}
            {q.error ? <p role="alert" className="field-error">{t('experience2.loading_failed')}</p> : rows.length === 0 && !q.loading ? (
                <EmptyState title={t('experience2.fees.none')} />
            ) : (
                <div className="table-wrap card">
                    <table className="table-std table-cards">
                        <thead><tr>
                            <th>{t('experience2.fees.kind')}</th><th>{t('experience2.fees.hour')}</th><th>{t('experience2.fees.value')}</th>
                            <th>{t('experience2.services.charge_code')}</th><th>{t('experience2.services.status')}</th>{canEdit && <th />}
                        </tr></thead>
                        <tbody>
                            {rows.map((r) => (
                                <tr key={r.id}>
                                    <td data-label={t('experience2.fees.kind')} className="font-medium">{t(`experience2.fees.${r.kind}`)}{r.label && <span className="block text-sm font-normal text-slate-500">{r.label}</span>}</td>
                                    <td data-label={t('experience2.fees.hour')}>{band(r)}</td>
                                    <td data-label={t('experience2.fees.value')}>{amount(r)}</td>
                                    <td data-label={t('experience2.services.charge_code')}>{r.charge_code}</td>
                                    <td data-label={t('experience2.services.status')}><span className={r.active ? 'badge-green' : 'badge-slate'}>{r.active ? t('experience2.active') : t('experience2.inactive')}</span></td>
                                    {canEdit && (
                                        <td className="btn-row">
                                            <Button variant="secondary" size="sm" icon={Pencil} onClick={() => setEdit(r)}>{t('experience2.edit')}</Button>
                                            <Button variant="ghost" size="sm" onClick={() => toggle(r)}>{r.active ? t('experience2.deactivate') : t('experience2.activate')}</Button>
                                        </td>
                                    )}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
            <Modal open={!!edit} onClose={() => setEdit(null)} title={edit?.id ? t('experience2.fees.edit') : t('experience2.fees.new')}
                footer={<><Button variant="secondary" onClick={() => setEdit(null)}>{t('experience2.cancel')}</Button><Button type="submit" form="rule-form" loading={busy}>{t('experience2.save')}</Button></>}>
                {edit && (
                    <form id="rule-form" onSubmit={save} className="space-y-3">
                        <Field label={t('experience2.fees.kind')} required>
                            <select className="select w-full" value={edit.kind} disabled={!!edit.id} onChange={(e) => setEdit({ ...edit, kind: e.target.value as Rule['kind'] })}>
                                <option value="early_checkin">{t('experience2.fees.early_checkin')}</option>
                                <option value="late">{t('experience2.fees.late')}</option>
                            </select>
                        </Field>
                        <Field label={t(edit.kind === 'late' ? 'experience2.fees.hour_late' : 'experience2.fees.hour_early')} hint={t('experience2.fees.hour_hint')} required>
                            <input className="input w-full" type="number" min={0} max={23} value={edit.after_hour ?? 0} onChange={(e) => setEdit({ ...edit, after_hour: Number(e.target.value) })} />
                        </Field>
                        <Field label={t('experience2.fees.fee_type')} required>
                            <select className="select w-full" value={edit.fee_type} onChange={(e) => setEdit({ ...edit, fee_type: e.target.value as Rule['fee_type'] })}>
                                <option value="percent_of_night">{t('experience2.fees.percent_of_night')}</option>
                                <option value="fixed">{t('experience2.fees.fixed')}</option>
                            </select>
                        </Field>
                        <Field label={t('experience2.fees.value')} required><input className="input w-full" type="number" min={1} max={edit.fee_type === 'percent_of_night' ? 100 : undefined} value={edit.value ?? 0} onChange={(e) => setEdit({ ...edit, value: Number(e.target.value) })} /></Field>
                        <Field label={t('experience2.services.charge_code')} required><input className="input w-full" maxLength={16} value={edit.charge_code ?? ''} onChange={(e) => setEdit({ ...edit, charge_code: e.target.value.toUpperCase() })} /></Field>
                        <Field label={t('experience2.fees.label')}><input className="input w-full" maxLength={60} value={edit.label ?? ''} onChange={(e) => setEdit({ ...edit, label: e.target.value })} /></Field>
                    </form>
                )}
            </Modal>
        </div>
    );
}

function ReviewsTab() {
    const t = useT();
    const [rating, setRating] = useState('');
    const [page, setPage] = useState(1);
    const q = useApi<Paged<Review>>(`experience/reviews?page=${page}${rating ? `&rating=${rating}` : ''}`);
    const rows = q.data?.data ?? [];
    return (
        <div className="space-y-3">
            <div className="max-w-xs">
                <Field label={t('experience2.reviews.filter')}>
                    <select className="select w-full" value={rating} onChange={(e) => { setRating(e.target.value); setPage(1); }}>
                        <option value="">{t('experience2.reviews.all')}</option>
                        {[5, 4, 3, 2, 1].map((n) => <option key={n} value={n}>{t('experience2.reviews.stars', { n })}</option>)}
                    </select>
                </Field>
            </div>
            {q.error ? <p role="alert" className="field-error">{t('experience2.loading_failed')}</p> : rows.length === 0 && !q.loading ? (
                <EmptyState title={t('experience2.reviews.none')} />
            ) : (
                <div className="table-wrap card">
                    <table className="table-std table-cards">
                        <thead><tr><th>{t('experience2.reviews.rating')}</th><th>{t('experience2.reviews.comment')}</th><th>{t('experience2.reviews.stay')}</th><th>{t('experience2.reviews.date')}</th></tr></thead>
                        <tbody>
                            {rows.map((r) => (
                                <tr key={r.id} className="align-top">
                                    <td data-label={t('experience2.reviews.rating')}>
                                        <span className="inline-flex items-center gap-1 font-medium"><Star className="h-4 w-4 text-brand-800" aria-hidden="true" />{r.rating}/5</span>
                                    </td>
                                    <td data-label={t('experience2.reviews.comment')}>{r.comment || <span className="text-slate-500">{t('experience2.reviews.no_comment')}</span>}</td>
                                    <td data-label={t('experience2.reviews.stay')}>{r.stay ? <>{r.stay.number}<span className="block text-sm text-slate-500">{r.stay.guest} · {r.stay.arrival} → {r.stay.departure}</span></> : '-'}</td>
                                    <td data-label={t('experience2.reviews.date')}>{new Date(r.submitted_at).toLocaleDateString()}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
            {(q.data?.last_page ?? 1) > 1 && (
                <div className="btn-row items-center">
                    <Button variant="secondary" disabled={page <= 1} onClick={() => setPage(page - 1)}>{t('experience2.reviews.prev')}</Button>
                    <span className="text-sm text-slate-700">{t('experience2.reviews.page', { p: page, n: q.data?.last_page ?? 1 })}</span>
                    <Button variant="secondary" disabled={page >= (q.data?.last_page ?? 1)} onClick={() => setPage(page + 1)}>{t('experience2.reviews.next')}</Button>
                </div>
            )}
        </div>
    );
}

export default function ExperienceSettings() {
    const t = useT();
    const perms = usePage<{ auth: { user: { permissions: string[] } | null } }>().props.auth.user?.permissions ?? [];
    const canEdit = perms.includes('experience.manage');
    const canView = canEdit || perms.includes('experience.view');
    const [tab, setTab] = useState('settings');
    const tabs = [
        { key: 'settings', label: t('experience2.tab.settings') },
        { key: 'services', label: t('experience2.tab.services') },
        { key: 'fees', label: t('experience2.tab.fees') },
        ...(canView ? [{ key: 'reviews', label: t('experience2.tab.reviews') }] : []),
    ];
    return (
        <AppLayout title={t('experience2.title')}>
            <Head title={t('experience2.title')} />
            <div className="space-y-4">
                <Tabs tabs={tabs} value={tab} onChange={setTab} label={t('experience2.tabs')} />
                <div role="tabpanel" id={`panel-${tab}`} aria-labelledby={`tab-${tab}`}>
                    {tab === 'settings' && <SettingsTab canEdit={canEdit} />}
                    {tab === 'services' && <ServicesTab canEdit={canEdit} />}
                    {tab === 'fees' && <FeesTab canEdit={canEdit} />}
                    {tab === 'reviews' && <ReviewsTab />}
                </div>
            </div>
        </AppLayout>
    );
}
