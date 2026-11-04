import AppLayout from '@/Layouts/AppLayout';
import QueryStatus from '@/Components/ui/QueryStatus';
import { Head } from '@inertiajs/react';
import { useState } from 'react';
import { useApi } from '@/lib/api';
import { useT } from '@/lib/i18n';
import { WorkforceTabs, wpost, msg } from './WorkforceTabs';

interface Anomaly { id: number; employee: string | null; date: string; kind: string; severity: string; detail: Record<string, unknown>; status: string }
interface Settings { geo_lat: number | null; geo_lng: number | null; geo_radius_m: number; geo_required: boolean; photo_required: boolean; late_tolerance_min: number; rest_hours_min: number; max_weekly_hours: number }

export default function WorkforceTime() {
    const t = useT();
    const list = useApi<Anomaly[]>('workforce/anomalies');
    const settings = useApi<Settings>('workforce/settings');
    const [form, setForm] = useState<Partial<Settings>>({});
    const [error, setError] = useState<string | null>(null);
    const act = async (fn: () => Promise<unknown>) => { setError(null); try { await fn(); list.reload(); settings.reload(); } catch (e) { setError(msg(e)); } };
    const s = { ...settings.data, ...form } as Settings;
    const num = (k: keyof Settings) => (e: React.ChangeEvent<HTMLInputElement>) => setForm({ ...form, [k]: Number(e.target.value) });
    return (
        <AppLayout title={t('workforce.time.title')} subtitle={t('workforce.time.subtitle')}>
            <Head title={t('workforce.time.title')} />
            <QueryStatus query={list} variant="table" />
            <WorkforceTabs active="/hr/workforce/time" />
            {error && <div className="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-2 text-sm text-red-700">{error}</div>}
            <div className="card mb-4 p-4 text-sm"><div className="mb-2 flex justify-between"><h2 className="font-semibold">{t('workforce.time.anomalies')}</h2>
                <button className="btn-secondary" onClick={() => act(() => wpost('workforce/scan'))}>{t('workforce.time.scan')}</button></div>
                {list.data?.length === 0 && <p className="text-slate-500">{t('workforce.none')}</p>}
                <ul className="divide-y divide-slate-100">{list.data?.map((a) => (
                    <li key={a.id} className="flex items-center justify-between py-2"><span>{a.date.slice(0, 10)} - {a.employee} - {t(`workforce.anomaly.${a.kind}`)} ({a.severity}) <span className="text-slate-500">{JSON.stringify(a.detail)}</span></span>
                        <span className="flex gap-2"><button className="btn-secondary" onClick={() => act(() => wpost(`workforce/anomalies/${a.id}/review`, { status: 'reviewed' }))}>{t('workforce.time.reviewed')}</button>
                            <button className="btn-secondary" onClick={() => act(() => wpost(`workforce/anomalies/${a.id}/review`, { status: 'dismissed' }))}>{t('workforce.time.dismiss')}</button></span></li>))}</ul></div>
            <form className="card grid gap-3 p-4 text-sm sm:grid-cols-3" onSubmit={(e) => { e.preventDefault(); act(() => wpost('workforce/settings', form, 'PUT')); }}>
                <h2 className="font-semibold sm:col-span-3">{t('workforce.time.settings')}</h2>
                <label>{t('workforce.time.lat')}<input type="number" step="any" className="input w-full" value={s.geo_lat ?? ''} onChange={num('geo_lat')} /></label>
                <label>{t('workforce.time.lng')}<input type="number" step="any" className="input w-full" value={s.geo_lng ?? ''} onChange={num('geo_lng')} /></label>
                <label>{t('workforce.time.radius')}<input type="number" className="input w-full" value={s.geo_radius_m ?? 150} onChange={num('geo_radius_m')} /></label>
                <label>{t('workforce.time.rest_hours')}<input type="number" className="input w-full" value={s.rest_hours_min ?? 11} onChange={num('rest_hours_min')} /></label>
                <label>{t('workforce.time.max_weekly')}<input type="number" className="input w-full" value={s.max_weekly_hours ?? 48} onChange={num('max_weekly_hours')} /></label>
                <label>{t('workforce.time.late_tolerance')}<input type="number" className="input w-full" value={s.late_tolerance_min ?? 10} onChange={num('late_tolerance_min')} /></label>
                <label className="flex items-center gap-2"><input type="checkbox" checked={!!s.geo_required} onChange={(e) => setForm({ ...form, geo_required: e.target.checked })} />{t('workforce.time.geo_required')}</label>
                <label className="flex items-center gap-2"><input type="checkbox" checked={!!s.photo_required} onChange={(e) => setForm({ ...form, photo_required: e.target.checked })} />{t('workforce.time.photo_required')}</label>
                <div><button className="btn-primary">{t('workforce.save')}</button></div>
            </form>
        </AppLayout>
    );
}
