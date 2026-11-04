import AppLayout from '@/Layouts/AppLayout';
import QueryStatus from '@/Components/ui/QueryStatus';
import { Head } from '@inertiajs/react';
import { Loader2 } from 'lucide-react';
import { useEffect, useState } from 'react';
import { api, ApiError, useApi } from '@/lib/api';
import { cn, formatCurrency, formatDate } from '@/lib/utils';
import { useT } from '@/lib/i18n';
import Alert from '@/Components/ui/Alert';

interface RatePlan { id: number; code: string; name: string }
interface RoomType { id: number; code: string; name: string }
interface Season { id: number; name: string; start_date: string; end_date: string; room_type_id: number | null; modifier_percent: number | null; rate: number | null }

const blank = { id: 0, name: '', start_date: '', end_date: '', room_type_id: '', mode: 'percent' as 'percent' | 'rate', value: '' };

export default function Seasons() {
    const t = useT();
    const plans = useApi<{ data: RatePlan[] }>('rate-plans?per_page=100');
    const types = useApi<{ data: RoomType[] }>('room-types?per_page=100');
    const [planId, setPlanId] = useState<number | null>(null);
    useEffect(() => {
        if (!planId && plans.data?.data.length) setPlanId(plans.data.data[0].id);
    }, [plans.data]);
    const seasons = useApi<{ data: Season[] }>(planId ? `rate-plans/${planId}/seasons` : null);
    const [form, setForm] = useState(blank);
    const [message, setMessage] = useState<{ ok: boolean; text: string } | null>(null);
    const [confirmDelete, setConfirmDelete] = useState<number | null>(null);

    const fail = (e: unknown) => setMessage({ ok: false, text: e instanceof ApiError ? e.message : String(e) });

    async function save() {
        if (!planId) return;
        setMessage(null);
        try {
            const body = JSON.stringify({
                name: form.name, start_date: form.start_date, end_date: form.end_date,
                room_type_id: form.room_type_id ? +form.room_type_id : null,
                modifier_percent: form.mode === 'percent' ? +form.value : null,
                rate: form.mode === 'rate' ? Math.round(+form.value) : null,
            });
            await api(form.id ? `rate-plans/${planId}/seasons/${form.id}` : `rate-plans/${planId}/seasons`, { method: form.id ? 'PATCH' : 'POST', body });
            setForm(blank);
            setMessage({ ok: true, text: t('res.seasons.saved') });
            seasons.reload();
        } catch (e) {
            fail(e);
        }
    }

    async function remove(id: number) {
        try {
            await api(`rate-plans/${planId}/seasons/${id}`, { method: 'DELETE' });
            setConfirmDelete(null);
            seasons.reload();
        } catch (e) {
            fail(e);
        }
    }

    const typeName = (id: number | null) => (id ? types.data?.data.find((rt) => rt.id === id)?.name ?? `#${id}` : t('res.seasons.all_types'));

    return (
        <AppLayout title={t('res.seasons.title')}>
            <Head title={t('res.seasons.short')} />
            <QueryStatus query={plans} variant="table" />
            <div className="mx-auto max-w-4xl space-y-4">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <select className="select sm:w-72" aria-label={t('res.rate_plan')} value={planId ?? ''} onChange={(e) => (setPlanId(+e.target.value), setForm(blank))}>
                        {plans.data?.data.map((p) => <option key={p.id} value={p.id}>{p.code} · {p.name}</option>)}
                    </select>
                </div>

                <section className="card space-y-3 p-4">
                    <h2 className="font-medium">{form.id ? t('res.seasons.edit') : t('res.seasons.new')}</h2>
                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        <label className="label">{t('res.seasons.name')}<input className="input w-full" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} aria-label={t('res.seasons.name_ph')} placeholder={t('res.seasons.name_ph')} /></label>
                        <label className="label">{t('res.seasons.from')}<input className="input w-full" type="date" value={form.start_date} onChange={(e) => setForm({ ...form, start_date: e.target.value })} /></label>
                        <label className="label">{t('res.seasons.to')}<input className="input w-full" type="date" min={form.start_date} value={form.end_date} onChange={(e) => setForm({ ...form, end_date: e.target.value })} /></label>
                        <label className="label">{t('res.room_type')}
                            <select className="select w-full" value={form.room_type_id} onChange={(e) => setForm({ ...form, room_type_id: e.target.value })}>
                                <option value="">{t('res.seasons.all_types')}</option>
                                {types.data?.data.map((rt) => <option key={rt.id} value={rt.id}>{rt.name}</option>)}
                            </select>
                        </label>
                        <label className="label">{t('res.seasons.price_rule')}
                            <select className="select w-full" value={form.mode} onChange={(e) => setForm({ ...form, mode: e.target.value as 'percent' | 'rate' })}>
                                <option value="percent">{t('res.seasons.mode_percent')}</option>
                                <option value="rate">{t('res.seasons.mode_rate')}</option>
                            </select>
                        </label>
                        <label className="label">{form.mode === 'percent' ? t('res.seasons.percent') : t('res.seasons.rate')}
                            <input className="input w-full" type="number" value={form.value} onChange={(e) => setForm({ ...form, value: e.target.value })} />
                        </label>
                    </div>
                    <div className="flex gap-2">
                        <button type="button" className="btn-primary" disabled={!form.name || !form.start_date || !form.end_date || form.value === ''} onClick={save}>{t('res.seasons.save')}</button>
                        {form.id > 0 && <button type="button" className="btn-secondary" onClick={() => setForm(blank)}>{t('common.cancel')}</button>}
                    </div>
                </section>

                {message && <Alert variant={message.ok ? 'success' : 'danger'}>{message.text}</Alert>}

                <section className="card p-4">
                    <h2 className="mb-2 font-medium">{t('res.seasons.short')}</h2>
                    {seasons.loading && !seasons.data ? <Loader2 className="h-5 w-5 animate-spin" /> : seasons.data?.data.length === 0 ? (
                        <p className="text-sm text-slate-500">{t('res.seasons.empty')}</p>
                    ) : (
                        <div className="space-y-2">
                            {seasons.data?.data.map((s) => (
                                <div key={s.id} className="flex flex-col gap-2 rounded-md border border-slate-200 p-3 text-sm sm:flex-row sm:items-center sm:justify-between">
                                    <div>
                                        <div className="font-medium">{s.name} <span className="badge-blue">{s.rate !== null ? formatCurrency(s.rate) : `${s.modifier_percent! > 0 ? '+' : ''}${s.modifier_percent}%`}</span></div>
                                        <div className="text-slate-600">{formatDate(s.start_date)} → {formatDate(s.end_date)} · {typeName(s.room_type_id)}</div>
                                    </div>
                                    <div className="flex gap-2">
                                        <button type="button" className="btn-secondary" onClick={() => setForm({
                                            id: s.id, name: s.name, start_date: s.start_date.slice(0, 10), end_date: s.end_date.slice(0, 10), room_type_id: s.room_type_id ? String(s.room_type_id) : '',
                                            mode: s.rate !== null ? 'rate' : 'percent', value: String(s.rate ?? s.modifier_percent ?? ''),
                                        })}>{t('common.edit')}</button>
                                        {confirmDelete === s.id ? (
                                            <>
                                                <button type="button" className="btn-primary" onClick={() => remove(s.id)}>{t('res.confirm_delete')}</button>
                                                <button type="button" className="btn-secondary" onClick={() => setConfirmDelete(null)}>{t('res.keep')}</button>
                                            </>
                                        ) : <button type="button" className="btn-secondary" onClick={() => setConfirmDelete(s.id)}>{t('common.delete')}</button>}
                                    </div>
                                </div>
                            ))}
                        </div>
                    )}
                </section>
            </div>
        </AppLayout>
    );
}
