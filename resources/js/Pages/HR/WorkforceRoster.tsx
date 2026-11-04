import AppLayout from '@/Layouts/AppLayout';
import { EmptyFor } from '@/Components/ui/EmptyStates';
import QueryStatus from '@/Components/ui/QueryStatus';
import { Head } from '@inertiajs/react';
import { useState } from 'react';
import { api, useApi } from '@/lib/api';
import { useT } from '@/lib/i18n';
import { WorkforceTabs, wpost, monday, addDays, msg } from './WorkforceTabs';
import Alert from '@/Components/ui/Alert';
import { useCan } from '@/lib/useCan';
import { ForecastRulesPanel, ShiftEditor } from './WorkforceGapd';

interface Shift { id: number; employee_id: number | null; date: string; starts_at: string; ends_at: string; role: string | null; employee?: { first_name: string; last_name: string } | null }
interface Issue { rule: string; message: string }
interface Roster { id: number; status: string; week_start: string; warnings: Issue[]; forecast: { date: string; arrivals: number; departures: number; inhouse: number }[]; shifts: Shift[]; violations?: Issue[] }

export default function WorkforceRoster() {
    const t = useT();
    const can = useCan();
    const [week, setWeek] = useState(addDays(monday(), 7));
    const [roster, setRoster] = useState<Roster | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [ack, setAck] = useState(false);
    const [view, setView] = useState<'roster' | 'forecast'>('roster');
    const [editing, setEditing] = useState(false);
    const list = useApi<{ id: number; week_start: string; status: string }[]>('workforce/rosters');
    const run = async (fn: () => Promise<unknown>) => { setError(null); try { await fn(); list.reload(); } catch (e) { setError(msg(e)); } };
    const open = (id: number) => run(async () => setRoster(await api<Roster>(`workforce/rosters/${id}`)));
    const generate = () => run(async () => setRoster((await wpost('workforce/rosters/generate', { week_start: week })) as Roster));
    const publish = () => run(async () => { if (roster) setRoster((await wpost(`workforce/rosters/${roster.id}/publish`, { acknowledge: ack })) as Roster); });
    const days = roster ? Array.from({ length: 7 }, (_, i) => addDays(roster.week_start.slice(0, 10), i)) : [];
    const issues: Issue[] = roster ? (roster.violations ?? roster.warnings ?? []) : [];

    return (
        <AppLayout title={t('workforce.roster.title')} subtitle={t('workforce.roster.subtitle')}>
            <Head title={t('workforce.roster.title')} />
            <WorkforceTabs active="/hr/workforce" />
            {error && <Alert variant="danger" className="mb-4">{error}</Alert>}
            <div className="tabs mb-4" role="tablist" aria-label={t('gapd.roster.views')}>
                <button type="button" role="tab" className="tab" aria-selected={view === 'roster'} onClick={() => setView('roster')}>{t('gapd.roster.tab_roster')}</button>
                <button type="button" role="tab" className="tab" aria-selected={view === 'forecast'} onClick={() => setView('forecast')}>{t('gapd.roster.tab_forecast')}</button>
            </div>
            {view === 'forecast' && <ForecastRulesPanel />}
            {view === 'roster' && (<>
            <div className="card mb-4 flex flex-wrap items-end gap-3 p-4 text-sm">
                <label>{t('workforce.roster.week')}<input type="date" className="input block" value={week} onChange={(e) => setWeek(e.target.value)} /></label>
                <button className="btn-primary" onClick={generate}>{t('workforce.roster.generate')}</button>
                <select className="select" aria-label={t('workforce.roster.open_existing')} value="" onChange={(e) => e.target.value && open(Number(e.target.value))}>
                    <option value="">{t('workforce.roster.open_existing')}</option>
                    {list.data?.map((r) => <option key={r.id} value={r.id}>{r.week_start.slice(0, 10)} - {t(`workforce.status.${r.status}`)}</option>)}
                </select>
            </div>
            <QueryStatus query={list} variant="list" rows={3} />
            {!roster && list.data?.length === 0 && <div className="card"><EmptyFor kind="staff" action={<button type="button" className="btn-primary" onClick={generate}>{t('workforce.roster.generate')}</button>} /></div>}
            {roster && (<>
                <div className="card mb-4 overflow-x-auto p-4 text-sm">
                    <h2 className="mb-2 font-semibold">{t('workforce.roster.forecast')}</h2>
                    <div className="table-wrap"><table className="table-auto w-full"><thead><tr className="text-left text-slate-500"><th>{t('workforce.date')}</th><th>{t('workforce.roster.arrivals')}</th><th>{t('workforce.roster.departures')}</th><th>{t('workforce.roster.inhouse')}</th></tr></thead>
                        <tbody>{roster.forecast.map((f) => <tr key={f.date}><td>{f.date}</td><td>{f.arrivals}</td><td>{f.departures}</td><td>{f.inhouse}</td></tr>)}</tbody></table></div>
                </div>
                {issues.length > 0 && (
                    <div className="card mb-4 p-4 text-sm"><h2 className="mb-2 font-semibold">{t('workforce.roster.issues')}</h2>
                        <ul className="list-disc pl-5">{issues.map((v, i) => <li key={i}>{t(`workforce.rule.${v.rule}`)} - {v.message}</li>)}</ul></div>
                )}
                <div className="card mb-4 overflow-x-auto p-4 text-sm">
                    <div className="mb-2 flex flex-wrap items-center justify-between gap-2"><h2 className="font-semibold">{roster.week_start.slice(0, 10)} - {t(`workforce.status.${roster.status}`)}</h2>
                        {roster.status === 'draft' && can('workforce.manage') && <button type="button" className="btn-secondary" onClick={() => setEditing(true)}>{t('gapd.roster.edit_shifts')}</button>}
                        {roster.status === 'draft' && can('workforce.publish') && <div className="flex flex-wrap items-center gap-3"><label className="flex items-center gap-1"><input type="checkbox" checked={ack} onChange={(e) => setAck(e.target.checked)} />{t('workforce.roster.acknowledge')}</label><button className="btn-primary" onClick={publish}>{t('workforce.roster.publish')}</button></div>}</div>
                    <div className="table-wrap"><table className="table-auto w-full"><thead><tr className="text-left text-slate-500">{days.map((d) => <th key={d} className="pr-3">{d.slice(5)}</th>)}</tr></thead>
                        <tbody><tr className="align-top">{days.map((d) => (
                            <td key={d} className="pr-3">{roster.shifts.filter((s) => s.date.slice(0, 10) === d).map((s) => (
                                <div key={s.id} className={`mb-1 rounded border px-2 py-1 ${s.employee_id ? 'border-slate-200' : 'border-amber-300 bg-amber-50'}`}>
                                    {s.starts_at.slice(0, 5)}-{s.ends_at.slice(0, 5)}<br />{s.employee ? `${s.employee.first_name} ${s.employee.last_name}` : t('workforce.open_shift')}</div>))}</td>))}</tr></tbody></table></div>
                </div>
                <ShiftEditor rosterId={roster.id} weekStart={roster.week_start} shifts={roster.shifts} open={editing} onClose={() => setEditing(false)}
                    onSaved={() => open(roster.id)} />
            </>)}
            </>)}
        </AppLayout>
    );
}
