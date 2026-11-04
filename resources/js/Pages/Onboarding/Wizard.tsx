import AppLayout from '@/Layouts/AppLayout';
import { Head } from '@inertiajs/react';
import { Check } from 'lucide-react';
import QueryStatus from '@/Components/ui/QueryStatus';
import { EmptyFor } from '@/Components/ui/EmptyStates';
import { useEffect, useState } from 'react';
import { api, ApiError, useApi } from '@/lib/api';
import { useT } from '@/lib/i18n';
import { OnboardingTabs } from './shared';
import WatermarkForm from './Watermark';

type Step = 'group' | 'hotel' | 'rooms' | 'rates' | 'outlets' | 'taxes' | 'users' | 'watermark' | 'golive';
interface State {
    hotel: Record<string, string | null> & { id: number };
    group: Record<string, string | null> | null;
    current_step: Step;
    steps: { key: Step; done: boolean; detected: boolean }[];
    checklist: { key: string; ok: boolean; blocking: boolean }[];
    went_live_at: string | null;
    counts: Record<string, number>;
}
interface TaxCode { id: number; code: string; name: string; kind: string; rate: string; fixed_amount: number; included_in_price: boolean; account: string; active: boolean }

const STEPS: Step[] = ['group', 'hotel', 'rooms', 'rates', 'outlets', 'taxes', 'users', 'watermark', 'golive'];

function Field({ label, value, onChange, type = 'text' }: { label: string; value: string; onChange: (v: string) => void; type?: string }) {
    return (
        <label className="block">
            <span className="label">{label}</span>
            <input className="input" type={type} value={value} onChange={(e) => onChange(e.target.value)} />
        </label>
    );
}

function useForm(init: Record<string, string>) {
    const [f, setF] = useState(init);
    return { f, set: (k: string) => (v: string) => setF((p) => ({ ...p, [k]: v })), reset: () => setF(init), setAll: setF };
}

export default function Wizard() {
    const t = useT();
    const state = useApi<State>('onboarding/state');
    const [step, setStep] = useState<Step>('group');
    const [msg, setMsg] = useState<{ ok: boolean; text: string } | null>(null);
    const [busy, setBusy] = useState(false);
    const s = state.data;

    useEffect(() => {
        if (s) setStep(s.current_step);
    }, [s?.current_step]);

    async function run(fn: () => Promise<unknown>, next = false) {
        setBusy(true);
        setMsg(null);
        try {
            await fn();
            if (next) {
                const i = STEPS.indexOf(step);
                const to = STEPS[Math.min(i + 1, STEPS.length - 1)];
                await api('onboarding/state', { method: 'PUT', body: JSON.stringify({ complete: [step], current_step: to }) });
                setStep(to);
            }
            setMsg({ ok: true, text: t('onboarding.saved') });
            state.reload();
        } catch (e) {
            const err = e instanceof ApiError ? e : null;
            const detail = err?.problem.errors ? Object.values(err.problem.errors).flat().join(' ') : err?.message;
            setMsg({ ok: false, text: detail || t('onboarding.error') });
        } finally {
            setBusy(false);
        }
    }

    function go(to: Step) {
        setStep(to);
        api('onboarding/state', { method: 'PUT', body: JSON.stringify({ current_step: to }) }).catch(() => undefined);
    }

    return (
        <AppLayout title={t('onboarding.title')} subtitle={t('onboarding.subtitle')}>
            <Head title={t('onboarding.title')} />
            <OnboardingTabs active="/onboarding" />
            <QueryStatus query={state} variant="detail" />
            {s && (
                <div className="grid gap-4 md:grid-cols-[220px_1fr]">
                    <ol className="card p-3 text-sm space-y-1">
                        {s.steps.map((st, i) => (
                            <li key={st.key}>
                                <button type="button" onClick={() => go(st.key)}
                                    className={`flex min-h-11 w-full items-center gap-2 rounded px-2 py-1.5 text-left ${step === st.key ? 'bg-slate-100 font-semibold text-slate-900' : 'text-slate-600 hover:bg-slate-50'}`}>
                                    <span className={`flex h-5 w-5 items-center justify-center rounded-full border text-xs ${st.done ? 'border-emerald-600 bg-emerald-600 text-white' : 'border-slate-300'}`}>
                                        {st.done ? <Check className="h-3 w-3" /> : i + 1}
                                    </span>
                                    {st.key === 'watermark' ? t('documents2.step.watermark') : t(`onboarding.step.${st.key}`)}
                                </button>
                            </li>
                        ))}
                    </ol>
                    <div className="card p-4">
                        <h2 className="mb-1 font-semibold text-slate-900">{step === 'watermark' ? t('documents2.step.watermark') : t(`onboarding.step.${step}`)}</h2>
                        {step !== 'watermark' && <p className="mb-4 text-sm text-slate-500">{t(`onboarding.help.${step}`)}</p>}
                        {msg && <p className={`mb-3 text-sm ${msg.ok ? 'text-emerald-700' : 'text-red-700'}`}>{msg.text}</p>}
                        {step === 'group' && <GroupStep s={s} busy={busy} run={run} />}
                        {step === 'hotel' && <HotelStep s={s} busy={busy} run={run} />}
                        {step === 'rooms' && <RoomsStep s={s} busy={busy} run={run} />}
                        {step === 'rates' && <RatesStep s={s} busy={busy} run={run} />}
                        {step === 'outlets' && <OutletsStep s={s} busy={busy} run={run} />}
                        {step === 'taxes' && <TaxesStep busy={busy} run={run} />}
                        {step === 'users' && <UsersStep s={s} busy={busy} run={run} />}
                        {step === 'watermark' && <WatermarkForm onNext={() => run(async () => undefined, true)} />}
                        {step === 'golive' && <GoLiveStep s={s} busy={busy} run={run} />}
                    </div>
                </div>
            )}
        </AppLayout>
    );
}

type StepProps = { s: State; busy: boolean; run: (fn: () => Promise<unknown>, next?: boolean) => void };

function Next({ busy, onClick, label }: { busy: boolean; onClick: () => void; label?: string }) {
    const t = useT();
    return (
        <button type="button" className="btn-primary" disabled={busy} onClick={onClick}>
            {label ?? t('onboarding.save_continue')}
        </button>
    );
}

function GroupStep({ s, busy, run }: StepProps) {
    const t = useT();
    const { f, set } = useForm({ name: s.group?.name ?? '', legal_name: s.group?.legal_name ?? '' });
    return (
        <div className="space-y-3 max-w-lg">
            <Field label={t('onboarding.f.group_name')} value={f.name} onChange={set('name')} />
            <Field label={t('onboarding.f.legal_name')} value={f.legal_name} onChange={set('legal_name')} />
            <Next busy={busy} onClick={() => run(() => api('onboarding/group', { method: 'PUT', body: JSON.stringify(f) }), true)} />
        </div>
    );
}

function HotelStep({ s, busy, run }: StepProps) {
    const t = useT();
    const keys = ['name', 'legal_name', 'tax_id', 'trade_register', 'address', 'city', 'phone', 'email'];
    const { f, set } = useForm(Object.fromEntries(keys.map((k) => [k, s.hotel[k] ?? ''])));
    return (
        <div className="grid gap-3 sm:grid-cols-2">
            {keys.map((k) => <Field key={k} label={t(`onboarding.f.${k}`)} value={f[k]} onChange={set(k)} />)}
            <div className="sm:col-span-2">
                <Next busy={busy} onClick={() => run(() => api(`hotels/${s.hotel.id}`, { method: 'PUT', body: JSON.stringify(Object.fromEntries(Object.entries(f).map(([k, v]) => [k, v || null]))) }), true)} />
            </div>
        </div>
    );
}

function RoomsStep({ s, busy, run }: StepProps) {
    const t = useT();
    const types = useApi<{ data: { id: number; code: string; name: string; base_rate: number }[] }>('room-types', [s.counts.room_types]);
    const type = useForm({ code: '', name: '', base_rate: '' });
    const room = useForm({ room_type_id: '', number: '', floor: '' });
    return (
        <div className="space-y-4">
            <p className="text-sm text-slate-600">{t('onboarding.counts', { types: s.counts.room_types, rooms: s.counts.rooms })} · <a className="underline" href="/onboarding/import">{t('onboarding.import_hint')}</a></p>
            <div className="grid gap-3 sm:grid-cols-4 items-end">
                <Field label={t('onboarding.f.code')} value={type.f.code} onChange={type.set('code')} />
                <Field label={t('onboarding.f.name')} value={type.f.name} onChange={type.set('name')} />
                <Field label={t('onboarding.f.base_rate')} value={type.f.base_rate} onChange={type.set('base_rate')} type="number" />
                <button type="button" className="btn-secondary" disabled={busy} onClick={() => run(async () => {
                    await api('room-types', { method: 'POST', body: JSON.stringify({ ...type.f, base_rate: Number(type.f.base_rate || 0) }) });
                    type.reset();
                })}>{t('onboarding.add_type')}</button>
            </div>
            <div className="grid gap-3 sm:grid-cols-4 items-end">
                <label className="block">
                    <span className="label">{t('onboarding.f.room_type')}</span>
                    <select className="select" value={room.f.room_type_id} onChange={(e) => room.set('room_type_id')(e.target.value)}>
                        <option value="">—</option>
                        {(types.data?.data ?? []).map((rt) => <option key={rt.id} value={rt.id}>{rt.code} · {rt.name}</option>)}
                    </select>
                </label>
                <Field label={t('onboarding.f.number')} value={room.f.number} onChange={room.set('number')} />
                <Field label={t('onboarding.f.floor')} value={room.f.floor} onChange={room.set('floor')} />
                <button type="button" className="btn-secondary" disabled={busy} onClick={() => run(async () => {
                    await api('rooms', { method: 'POST', body: JSON.stringify({ ...room.f, room_type_id: Number(room.f.room_type_id), floor: room.f.floor || null }) });
                    room.setAll({ ...room.f, number: '' });
                })}>{t('onboarding.add_room')}</button>
            </div>
            <Next busy={busy} onClick={() => run(async () => undefined, true)} />
        </div>
    );
}

function RatesStep({ s, busy, run }: StepProps) {
    const t = useT();
    const plan = useForm({ code: 'BAR', name: '', meal_plan: 'RO' });
    return (
        <div className="space-y-3 max-w-xl">
            <p className="text-sm text-slate-600">{t('onboarding.rate_plans', { count: s.counts.rate_plans })}</p>
            <div className="grid gap-3 sm:grid-cols-3">
                <Field label={t('onboarding.f.code')} value={plan.f.code} onChange={plan.set('code')} />
                <Field label={t('onboarding.f.name')} value={plan.f.name} onChange={plan.set('name')} />
                <label className="block">
                    <span className="label">{t('onboarding.f.meal_plan')}</span>
                    <select className="select" value={plan.f.meal_plan} onChange={(e) => plan.set('meal_plan')(e.target.value)}>
                        {['RO', 'BB', 'HB', 'FB', 'AI'].map((m) => <option key={m}>{m}</option>)}
                    </select>
                </label>
            </div>
            <div className="flex gap-2">
                <button type="button" className="btn-secondary" disabled={busy} onClick={() => run(() => api('rate-plans', { method: 'POST', body: JSON.stringify(plan.f) }))}>{t('onboarding.add_plan')}</button>
                <Next busy={busy} onClick={() => run(async () => undefined, true)} />
            </div>
        </div>
    );
}

function OutletsStep({ s, busy, run }: StepProps) {
    const t = useT();
    const o = useForm({ code: '', name: '', kind: 'restaurant' });
    return (
        <div className="space-y-3 max-w-xl">
            <p className="text-sm text-slate-600">{t('onboarding.outlets', { count: s.counts.outlets })}</p>
            <div className="grid gap-3 sm:grid-cols-3">
                <Field label={t('onboarding.f.code')} value={o.f.code} onChange={o.set('code')} />
                <Field label={t('onboarding.f.name')} value={o.f.name} onChange={o.set('name')} />
                <label className="block">
                    <span className="label">{t('onboarding.f.kind')}</span>
                    <select className="select" value={o.f.kind} onChange={(e) => o.set('kind')(e.target.value)}>
                        {['restaurant', 'bar', 'room_service', 'spa', 'laundry', 'minibar', 'shop', 'events'].map((k) => <option key={k} value={k}>{t(`onboarding.outlet.${k}`)}</option>)}
                    </select>
                </label>
            </div>
            <div className="flex gap-2">
                <button type="button" className="btn-secondary" disabled={busy} onClick={() => run(async () => { await api('outlets', { method: 'POST', body: JSON.stringify(o.f) }); o.reset(); })}>{t('onboarding.add_outlet')}</button>
                <Next busy={busy} onClick={() => run(async () => undefined, true)} />
            </div>
        </div>
    );
}

function TaxesStep({ busy, run }: Omit<StepProps, 's'>) {
    const t = useT();
    const taxes = useApi<TaxCode[] | { data: TaxCode[] }>('setup/tax-codes');
    const list = Array.isArray(taxes.data) ? taxes.data : taxes.data?.data ?? [];
    const [rates, setRates] = useState<Record<string, string>>({});
    return (
        <div className="space-y-3">
            <QueryStatus query={taxes} variant="table" rows={3} />
            {taxes.data && list.length === 0 && <EmptyFor kind="generic" />}
            <div className="table-wrap"><table className="table-auto w-full text-sm">
                <thead><tr className="text-left text-slate-500"><th className="py-1">{t('onboarding.f.code')}</th><th>{t('onboarding.f.name')}</th><th>{t('onboarding.f.rate')}</th><th /></tr></thead>
                <tbody>
                    {list.map((tc) => (
                        <tr key={tc.id} className="border-t border-slate-100">
                            <td className="py-1.5">{tc.code}</td>
                            <td>{tc.name}</td>
                            <td>
                                <input className="input w-28" value={rates[tc.code] ?? (tc.kind === 'percent' ? tc.rate : String(tc.fixed_amount))}
                                    onChange={(e) => setRates((p) => ({ ...p, [tc.code]: e.target.value }))} />
                            </td>
                            <td>
                                <button type="button" className="btn-secondary" disabled={busy || rates[tc.code] === undefined} onClick={() => run(() => api('setup/tax-codes', {
                                    method: 'PUT',
                                    body: JSON.stringify({ code: tc.code, name: tc.name, account: tc.account, ...(tc.kind === 'percent' ? { rate: Number(rates[tc.code]) } : { fixed_amount: Number(rates[tc.code]) }) }),
                                }))}>{t('onboarding.save')}</button>
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table></div>
            <Next busy={busy} onClick={() => run(async () => undefined, true)} label={t('onboarding.taxes_reviewed')} />
        </div>
    );
}

function UsersStep({ s, busy, run }: StepProps) {
    const t = useT();
    const roles = useApi<{ name: string }[] | { data: { name: string }[] }>('roles');
    const list = Array.isArray(roles.data) ? roles.data : roles.data?.data ?? [];
    const u = useForm({ name: '', email: '', role: 'receptionist' });
    return (
        <div className="space-y-3 max-w-xl">
            <p className="text-sm text-slate-600">{t('onboarding.users', { count: s.counts.users })}</p>
            <div className="grid gap-3 sm:grid-cols-3">
                <Field label={t('onboarding.f.name')} value={u.f.name} onChange={u.set('name')} />
                <Field label={t('onboarding.f.email')} value={u.f.email} onChange={u.set('email')} type="email" />
                <label className="block">
                    <span className="label">{t('onboarding.f.role')}</span>
                    <select className="select" value={u.f.role} onChange={(e) => u.set('role')(e.target.value)}>
                        {list.map((r) => <option key={r.name} value={r.name}>{r.name}</option>)}
                    </select>
                </label>
            </div>
            <div className="flex gap-2">
                <button type="button" className="btn-secondary" disabled={busy} onClick={() => run(async () => {
                    await api('users', { method: 'POST', body: JSON.stringify({ name: u.f.name, email: u.f.email, roles: [u.f.role], hotel_ids: [s.hotel.id] }) });
                    u.reset();
                })}>{t('onboarding.invite')}</button>
                <Next busy={busy} onClick={() => run(async () => undefined, true)} />
            </div>
        </div>
    );
}

function GoLiveStep({ s, busy, run }: StepProps) {
    const t = useT();
    const ready = s.checklist.every((c) => !c.blocking || c.ok);
    return (
        <div className="space-y-3">
            <ul className="space-y-1 text-sm">
                {s.checklist.map((c) => (
                    <li key={c.key} className="flex items-center gap-2">
                        <span className={`inline-block h-2.5 w-2.5 rounded-full ${c.ok ? 'bg-emerald-600' : c.blocking ? 'bg-red-600' : 'bg-amber-500'}`} />
                        <span className={c.ok ? 'text-slate-700' : 'text-slate-900'}>{t(`onboarding.check.${c.key}`)}</span>
                        {!c.blocking && <span className="text-xs text-slate-500">{t('onboarding.optional')}</span>}
                    </li>
                ))}
            </ul>
            {s.went_live_at ? (
                <p className="text-sm text-emerald-700">{t('onboarding.live_since', { date: new Date(s.went_live_at).toLocaleString() })}</p>
            ) : (
                <button type="button" className="btn-primary" disabled={busy || !ready} onClick={() => run(() => api('onboarding/go-live', { method: 'POST' }))}>{t('onboarding.go_live')}</button>
            )}
        </div>
    );
}
