import { useEffect, useState } from 'react';
import { api, ApiError, Problem } from '@/lib/api';
import { formatCurrency } from '@/lib/utils';
import { useT } from '@/lib/i18n';
import Alert from '@/Components/ui/Alert';

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
    const [typesState, setTypesState] = useState<'loading' | 'ok' | 'error'>('loading');
    const [typesTry, setTypesTry] = useState(0);
    const [total, setTotal] = useState<number | null>(null);
    const [problem, setProblem] = useState<Problem | null>(null);
    const [saving, setSaving] = useState(false);

    useEffect(() => {
        setTypesState('loading');
        api<{ data: RoomType[] }>('room-types?per_page=200').then((r) => { setTypes(r.data); setTypesState('ok'); }).catch(() => { setTypes([]); setTypesState('error'); });
    }, [typesTry]);

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
                    <select className="select w-full" value={typeId} disabled={typesState === 'loading'} onChange={(e) => setTypeId(e.target.value ? +e.target.value : '')}>
                        <option value="">{typesState === 'loading' ? t('states.loading') : t('res.edit.keep_current')}</option>
                        {types.map((rt) => <option key={rt.id} value={rt.id}>{rt.name}</option>)}
                    </select>
                    {typesState === 'error' && (
                        <span className="mt-1 flex items-center gap-2 text-xs text-red-700" role="alert">
                            {t('states3.room_types_error')}
                            <button type="button" className="btn-secondary btn-sm" onClick={() => setTypesTry((n) => n + 1)}>{t('states.retry')}</button>
                        </span>
                    )}
                    {typesState === 'ok' && types.length === 0 && <span className="mt-1 block text-xs text-slate-500">{t('states3.room_types_none')}</span>}
                </label>
            </div>
            {problem && (
                <Alert variant="danger">
                    {problem.status === 409 ? `${t('res.create.sold_out')} : ` : problem.status === 422 ? `${t('res.edit.cannot_change')} : ` : ''}{problem.detail ?? problem.title}
                </Alert>
            )}
            <div className="flex items-center justify-between text-sm">
                <span>{t('res.total')}: <b>{formatCurrency(total ?? reservation.total_amount, reservation.currency)}</b>{total !== null && ` (${t('res.edit.updated')})`}</span>
                <button type="button" className="btn-primary" disabled={saving || departure <= arrival} onClick={save}>{t('res.edit.save')}</button>
            </div>
        </section>
    );
}
