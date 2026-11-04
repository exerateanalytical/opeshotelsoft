import { useEffect, useState } from 'react';
import { CheckCircle2, XCircle } from 'lucide-react';
import Modal from '@/Components/ui/Modal';
import Button from '@/Components/ui/Button';
import { api, ApiError } from '@/lib/api';
import { formatDate } from '@/lib/utils';
import { useT } from '@/lib/i18n';

export interface AssignRow {
    id: number;
    number: string;
    guest: string;
    roomType: string;
    arrival: string;
    departure: string;
    currentRoom: string | null;
}

interface RoomOption { id: number; number: string; hk_status: string }
interface RowState { rooms: RoomOption[] | null; loadError?: string; choice: string; busy: boolean; result?: { ok: boolean; text: string } }

/** Bulk "assign room": one picker per reservation, limited to free rooms of its booked type for its dates. */
export default function BulkAssignRoomModal({ rows, onClose, onDone }: { rows: AssignRow[] | null; onClose: () => void; onDone: () => void }) {
    const t = useT();
    const [state, setState] = useState<Record<number, RowState>>({});
    const [changed, setChanged] = useState(false);

    useEffect(() => {
        if (!rows) return;
        setChanged(false);
        setState(Object.fromEntries(rows.map((r) => [r.id, { rooms: null, choice: '', busy: false }])));
        rows.forEach((r) => {
            api<RoomOption[]>(`reservations/${r.id}/available-rooms`)
                .then((rooms) => setState((s) => ({ ...s, [r.id]: { ...s[r.id], rooms } })))
                .catch((e) => setState((s) => ({ ...s, [r.id]: { ...s[r.id], rooms: [], loadError: e instanceof ApiError ? e.message : String(e) } })));
        });
    }, [rows]);

    const patch = (id: number, p: Partial<RowState>) => setState((s) => ({ ...s, [id]: { ...s[id], ...p } }));

    async function apply(r: AssignRow) {
        const st = state[r.id];
        if (!st?.choice) return;
        patch(r.id, { busy: true, result: undefined });
        try {
            await api(`reservations/${r.id}/assign-room`, { method: 'POST', body: JSON.stringify({ room_id: Number(st.choice) }) });
            const num = st.rooms?.find((x) => String(x.id) === st.choice)?.number ?? '';
            patch(r.id, { busy: false, result: { ok: true, text: t('gapg.assign.ok', { room: num }) } });
            setChanged(true);
        } catch (e) {
            patch(r.id, { busy: false, result: { ok: false, text: e instanceof ApiError ? e.message : String(e) } });
        }
    }

    async function applyAll() {
        for (const r of rows ?? []) {
            const st = state[r.id];
            if (st?.choice && !st.result?.ok) await apply(r);
        }
    }

    const close = () => { if (changed) onDone(); onClose(); };
    const pending = (rows ?? []).filter((r) => state[r.id]?.choice && !state[r.id]?.result?.ok).length;
    const anyBusy = Object.values(state).some((s) => s.busy);

    return (
        <Modal open={!!rows} onClose={close} size="xl" title={t('gapg.assign.title', { count: rows?.length ?? 0 })}
            footer={<>
                <Button variant="secondary" onClick={close}>{t('gapg.close')}</Button>
                <Button variant="primary" loading={anyBusy} disabled={!pending} onClick={applyAll}>{t('gapg.assign.apply_all', { count: pending })}</Button>
            </>}>
            <p className="mb-3 text-sm text-slate-600">{t('gapg.assign.hint')}</p>
            <div className="table-wrap">
                <table className="table-std table-cards w-full text-sm">
                    <caption className="sr-only">{t('gapg.assign.title', { count: rows?.length ?? 0 })}</caption>
                    <thead>
                        <tr>
                            <th scope="col">{t('res.confirmation')}</th>
                            <th scope="col">{t('res.guest')}</th>
                            <th scope="col">{t('gapg.dates')}</th>
                            <th scope="col">{t('gapg.assign.room')}</th>
                            <th scope="col"><span className="sr-only">{t('gape.actions')}</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        {(rows ?? []).map((r) => {
                            const st = state[r.id];
                            const selId = `gapg-room-${r.id}`;
                            return (
                                <tr key={r.id}>
                                    <td data-label={t('res.confirmation')} className="font-mono">{r.number}</td>
                                    <td data-label={t('res.guest')}>{r.guest}</td>
                                    <td data-label={t('gapg.dates')}>{formatDate(r.arrival)} - {formatDate(r.departure)} <span className="text-slate-500">({r.roomType})</span></td>
                                    <td data-label={t('gapg.assign.room')}>
                                        <label htmlFor={selId} className="sr-only">{t('gapg.assign.room_for', { number: r.number })}</label>
                                        <select id={selId} className="select min-w-36" disabled={!st?.rooms || st.busy || st.result?.ok}
                                            value={st?.choice ?? ''} onChange={(e) => patch(r.id, { choice: e.target.value, result: undefined })}>
                                            <option value="">{!st?.rooms ? t('gapg.loading') : st.rooms.length ? (r.currentRoom ? t('gapg.assign.current', { room: r.currentRoom }) : t('gapg.assign.pick')) : t('gapg.assign.none')}</option>
                                            {st?.rooms?.map((o) => <option key={o.id} value={o.id}>{o.number}{o.hk_status !== 'clean' && o.hk_status !== 'inspected' ? ` (${o.hk_status})` : ''}</option>)}
                                        </select>
                                        {st?.loadError && <p className="mt-1 text-sm text-red-700">{st.loadError}</p>}
                                    </td>
                                    <td data-label={t('gape.actions')}>
                                        {st?.result ? (
                                            <span role="status" className={st.result.ok ? 'inline-flex items-center gap-1 text-sm text-green-800' : 'inline-flex items-center gap-1 text-sm text-red-700'}>
                                                {st.result.ok ? <CheckCircle2 className="h-4 w-4" aria-hidden="true" /> : <XCircle className="h-4 w-4" aria-hidden="true" />}{st.result.text}
                                            </span>
                                        ) : null}
                                        {!st?.result?.ok && (
                                            <Button size="sm" variant="secondary" className="ml-2" loading={st?.busy} disabled={!st?.choice} onClick={() => apply(r)}>{t('gapg.assign.apply')}</Button>
                                        )}
                                    </td>
                                </tr>
                            );
                        })}
                    </tbody>
                </table>
            </div>
        </Modal>
    );
}
