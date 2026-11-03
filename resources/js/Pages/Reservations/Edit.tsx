import { useEffect, useState } from 'react';
import { api, ApiError, Problem } from '@/lib/api';
import { formatCurrency } from '@/lib/utils';
import { useT } from '@/lib/i18n';

interface RoomType { id: number; name: string }
interface Props {
    reservation: { id: number; arrival: string; departure: string; room_type_id?: number; total_amount: number; currency?: string };
    onSaved?: () => void;
}

/** "Edit stay" panel: change dates or room type via PATCH reservations/{id}. */
export default function EditStay({ reservation, onSaved }: Props) {
    const t = useT();
    const [arrival, setArrival] = useState(reservation.arrival.slice(0, 10));
    const [departure, setDeparture] = useState(reservation.departure.slice(0, 10));
    const [typeId, setTypeId] = useState<number | ''>(reservation.room_type_id ?? '');
    const [types, setTypes] = useState<RoomType[]>([]);
    const [total, setTotal] = useState<number | null>(null);
    const [problem, setProblem] = useState<Problem | null>(null);
    const [saving, setSaving] = useState(false);

    useEffect(() => {
        api<{ data: RoomType[] }>('room-types?per_page=200').then((r) => setTypes(r.data)).catch(() => setTypes([]));
    }, []);

    async function save() {
        setSaving(true);
        setProblem(null);
        try {
            const r = await api<{ total_amount: number }>(`reservations/${reservation.id}`, {
                method: 'PATCH',
                body: JSON.stringify({ arrival, departure, ...(typeId ? { room_type_id: typeId } : {}) }),
            });
            setTotal(r.total_amount);
            onSaved?.();
        } catch (e) {
            setProblem(e instanceof ApiError ? e.problem : { type: 'network', title: t('res.network_error'), status: 0, detail: String(e) });
        } finally {
            setSaving(false);
        }
    }

    return (
        <section className="card space-y-3 p-4">
            <h2 className="font-medium">{t('res.edit.title')}</h2>
            <div className="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-3">
                <label className="label">{t('res.arrival')}<input className="input w-full" type="date" value={arrival} onChange={(e) => setArrival(e.target.value)} /></label>
                <label className="label">{t('res.departure')}<input className="input w-full" type="date" value={departure} min={arrival} onChange={(e) => setDeparture(e.target.value)} /></label>
                <label className="label">{t('res.room_type')}
                    <select className="input w-full" value={typeId} onChange={(e) => setTypeId(e.target.value ? +e.target.value : '')} aria-label={t('res.edit.keep_current')}>
                        <option value="">{t('res.edit.keep_current')}</option>
                        {types.map((rt) => <option key={rt.id} value={rt.id}>{rt.name}</option>)}
                    </select>
                </label>
            </div>
            {problem && (
                <p className="rounded-md border border-red-300 bg-red-50 p-2 text-sm text-red-800">
                    {problem.status === 409 ? `${t('res.create.sold_out')} : ` : problem.status === 422 ? `${t('res.edit.cannot_change')} : ` : ''}{problem.detail ?? problem.title}
                </p>
            )}
            <div className="flex items-center justify-between text-sm">
                <span>{t('res.total')}: <b>{formatCurrency(total ?? reservation.total_amount, reservation.currency)}</b>{total !== null && ` (${t('res.edit.updated')})`}</span>
                <button type="button" className="btn-primary" disabled={saving || departure <= arrival} onClick={save}>{t('res.edit.save')}</button>
            </div>
        </section>
    );
}
