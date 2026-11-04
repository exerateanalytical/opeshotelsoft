import { useMemo, useState } from 'react';
import { api, useApi } from '@/lib/api';
import { useT } from '@/lib/i18n';
import { formatDate, formatDayShort } from '@/lib/utils';
import QueryStatus from '@/Components/ui/QueryStatus';
import Alert from '@/Components/ui/Alert';
import Field from '@/Components/ui/Field';
import Modal from '@/Components/ui/Modal';
import { useSplitView } from '@/Components/ui/MasterDetail';

interface Shift { id: number; date: string; starts_at: string; ends_at: string; role: string | null; employee_id: number; employee?: { id: number; first_name: string; last_name: string } | null }
interface SwapReq { id: number; kind?: string; status: string; requester: string | null; counterpart: string | null; requester_id: number; counterpart_id: number | null; shift?: { date: string; starts_at: string; ends_at: string } | null }
interface Task { id: number; title?: string; name?: string; due_on: string | null; employee?: { first_name: string; last_name: string; number: string } | null }

const iso = (d: Date) => d.toISOString().slice(0, 10);
const hm = (s: string) => String(s).slice(0, 5);

/** MS1: next 14 days of published shifts with a swap request (workforce self-service), plus open swap requests. */
export function MyShiftsPanel() {
    const t = useT();
    const wide = useSplitView();
    const from = iso(new Date());
    const to = iso(new Date(Date.now() + 14 * 86400000));
    const mine = useApi<Shift[]>(`workforce/my-shifts?from=${from}&to=${to}`);
    const colleagues = useApi<Shift[]>('workforce/colleague-shifts');
    const reqs = useApi<SwapReq[]>('workforce/requests?scope=mine');
    const [swap, setSwap] = useState<{ shift: Shift; counterpart: string; target: string; note: string } | null>(null);
    const [err, setErr] = useState<string | null>(null);
    const [ok, setOk] = useState<string | null>(null);

    const people = useMemo(() => {
        const m = new Map<number, string>();
        (colleagues.data ?? []).forEach((s) => s.employee && m.set(s.employee_id, `${s.employee.first_name} ${s.employee.last_name}`));
        return [...m.entries()];
    }, [colleagues.data]);
    const myId = mine.data?.[0]?.employee_id ?? null;
    const targets =(colleagues.data ?? []).filter((s) => swap && String(s.employee_id) === swap.counterpart);

    const act = async (fn: () => Promise<unknown>, done: string) => {
        setErr(null); setOk(null);
        try { await fn(); setOk(done); reqs.reload(); return true; } catch (e) { setErr(e instanceof Error ? e.message : String(e)); return false; }
    };

    return (
        <div className="space-y-3">
            {err && <Alert variant="danger" onDismiss={() => setErr(null)}>{err}</Alert>}
            {ok && <Alert variant="success" onDismiss={() => setOk(null)}>{ok}</Alert>}
            <QueryStatus query={mine} variant="list" rows={3} />
            {mine.data && (
                <div className="card divide-y divide-slate-100">
                    <h2 className="p-3 font-semibold">{t('ms1.me.shifts_title')}</h2>
                    {mine.data.map((s) => (
                        <div key={s.id} className="flex items-center justify-between gap-2 p-3">
                            <span>{formatDayShort(s.date)} · {hm(s.starts_at)} - {hm(s.ends_at)}{s.role ? <span className="block text-sm text-slate-600">{s.role}</span> : null}</span>
                            <button type="button" className="btn-secondary" onClick={() => setSwap({ shift: s, counterpart: '', target: '', note: '' })}>{t('ms1.me.swap')}</button>
                        </div>
                    ))}
                    {!mine.data.length && <p className="p-3 text-slate-600">{t('ms1.me.no_shifts')}</p>}
                </div>
            )}

            {reqs.data && reqs.data.length > 0 && (
                <div className="card divide-y divide-slate-100">
                    <h2 className="p-3 font-semibold">{t('ms1.me.requests_title')}</h2>
                    {reqs.data.map((r) => (
                        <div key={r.id} className="flex items-center justify-between gap-2 p-3">
                            <span>{r.shift ? `${formatDate(r.shift.date)} ${hm(r.shift.starts_at)}` : `#${r.id}`}<span className="block text-sm text-slate-600">{r.requester}{r.counterpart ? ` → ${r.counterpart}` : ''} · {t(`ms1.me.st.${r.status}`)}</span></span>
                            {myId !== null && r.counterpart_id === myId && r.status === 'pending' ? (
                                <span className="flex gap-2">
                                    <button type="button" className="btn-primary" onClick={() => act(() => api(`workforce/requests/${r.id}/respond`, { method: 'POST', body: JSON.stringify({ accept: true }) }), t('ms1.me.responded'))}>{t('ms1.me.accept')}</button>
                                    <button type="button" className="btn-secondary" onClick={() => act(() => api(`workforce/requests/${r.id}/respond`, { method: 'POST', body: JSON.stringify({ accept: false }) }), t('ms1.me.responded'))}>{t('ms1.me.decline')}</button>
                                </span>
                            ) : (r.requester_id === myId || myId === null) && ['pending', 'accepted'].includes(r.status) && (
                                <button type="button" className="btn-secondary" onClick={() => act(() => api(`workforce/requests/${r.id}/cancel`, { method: 'POST', body: '{}' }), t('ms1.me.cancelled'))}>{t('ms1.me.cancel_request')}</button>
                            )}
                        </div>
                    ))}
                </div>
            )}

            {swap && (
                <Modal open title={t('ms1.me.swap_title', { date: formatDate(swap.shift.date) })} variant={wide ? 'dialog' : 'sheet'} onClose={() => setSwap(null)} formId="swap-form"
                    footer={<><button type="button" className="btn-secondary" onClick={() => setSwap(null)}>{t('common.cancel')}</button><button type="submit" form="swap-form" className="btn-primary">{t('ms1.me.send_swap')}</button></>}>
                    <form id="swap-form" className="space-y-3" onSubmit={async (e) => {
                        e.preventDefault();
                        const body = { roster_shift_id: swap.shift.id, counterpart_id: Number(swap.counterpart), target_shift_id: swap.target ? Number(swap.target) : null, note: swap.note || null };
                        if (await act(() => api('workforce/swaps', { method: 'POST', body: JSON.stringify(body) }), t('ms1.me.swap_sent'))) setSwap(null);
                    }}>
                        <Field label={t('ms1.me.colleague')}>
                            <select className="select" required value={swap.counterpart} onChange={(e) => setSwap({ ...swap, counterpart: e.target.value, target: '' })}>
                                <option value="">{t('ms1.me.pick')}</option>
                                {people.map(([id, name]) => <option key={id} value={id}>{name}</option>)}
                            </select>
                        </Field>
                        <Field label={t('ms1.me.their_shift')}>
                            <select className="select" value={swap.target} onChange={(e) => setSwap({ ...swap, target: e.target.value })}>
                                <option value="">{t('ms1.me.give_away')}</option>
                                {targets.map((s) => <option key={s.id} value={s.id}>{formatDate(s.date)} {hm(s.starts_at)} - {hm(s.ends_at)}</option>)}
                            </select>
                        </Field>
                        <Field label={t('ms1.me.note')}>
                            <input className="input" maxLength={200} value={swap.note} onChange={(e) => setSwap({ ...swap, note: e.target.value })} />
                        </Field>
                    </form>
                </Modal>
            )}
        </div>
    );
}

/** MS1: onboarding tasks owned by my role (GET hr/onboarding-tasks/mine), mark done (POST hr/onboarding-tasks/{id}/complete). */
export function MyOnboardingPanel() {
    const t = useT();
    const q = useApi<Task[]>('hr/onboarding-tasks/mine');
    const [err, setErr] = useState<string | null>(null);
    return (
        <div className="space-y-3">
            {err && <Alert variant="danger" onDismiss={() => setErr(null)}>{err}</Alert>}
            <QueryStatus query={q} variant="list" rows={3} />
            {q.data && (
                <div className="card divide-y divide-slate-100">
                    {q.data.map((x) => (
                        <div key={x.id} className="flex items-center justify-between gap-2 p-3">
                            <span>{x.title ?? x.name ?? `#${x.id}`}<span className="block text-sm text-slate-600">{x.employee ? `${x.employee.first_name} ${x.employee.last_name}` : ''}{x.due_on ? ` · ${t('ms1.me.due')} ${formatDate(x.due_on)}` : ''}</span></span>
                            <button type="button" className="btn-secondary" onClick={async () => { setErr(null); try { await api(`hr/onboarding-tasks/${x.id}/complete`, { method: 'POST', body: '{}' }); q.reload(); } catch (e) { setErr(e instanceof Error ? e.message : String(e)); } }}>{t('ms1.me.done')}</button>
                        </div>
                    ))}
                    {!q.data.length && <p className="p-3 text-slate-600">{t('ms1.me.no_tasks')}</p>}
                </div>
            )}
        </div>
    );
}
