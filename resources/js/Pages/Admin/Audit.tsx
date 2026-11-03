import AppLayout from '@/Layouts/AppLayout';
import { Head } from '@inertiajs/react';
import { Loader2 } from 'lucide-react';
import { useState } from 'react';
import { useApi } from '@/lib/api';
import { formatDateTime } from '@/lib/utils';
import { useT } from '@/lib/i18n';

interface Entry {
    id: number;
    description: string;
    event: string | null;
    subject_type: string | null;
    subject_id: number | null;
    created_at: string;
    causer: { id: number; name: string } | null;
    properties: { attributes?: Record<string, unknown>; old?: Record<string, unknown> } | null;
}
interface Page { data: Entry[]; current_page: number; last_page: number; total: number }
interface Filters { users: { id: number; name: string }[]; subject_types: string[]; events: string[] }

const short = (type: string | null) => (type ? type.split('\\').pop() : '—');

function Changes({ p }: { p: Entry['properties'] }) {
    const attrs = p?.attributes ?? {};
    const keys = Object.keys(attrs);
    if (keys.length === 0) return <span className="text-slate-500">—</span>;
    return (
        <ul className="space-y-0.5">
            {keys.map((k) => (
                <li key={k} className="text-xs text-slate-600 break-all">
                    <span className="font-mono">{k}</span>: {p?.old && k in p.old ? <><s className="text-slate-500">{String(p.old[k] ?? '∅')}</s> → </> : null}{String(attrs[k] ?? '∅')}
                </li>
            ))}
        </ul>
    );
}

export default function AuditIndex() {
    const t = useT();
    const [f, setF] = useState({ user_id: '', subject_type: '', event: '', from: '', to: '' });
    const [page, setPage] = useState(1);
    const filters = useApi<Filters>('admin/audit/filters');
    const qs = new URLSearchParams({ ...Object.fromEntries(Object.entries(f).filter(([, v]) => v)), page: String(page), per_page: '50' }).toString();
    const log = useApi<Page>(`admin/audit?${qs}`, [qs]);
    const set = (k: keyof typeof f) => (e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement>) => { setPage(1); setF({ ...f, [k]: e.target.value }); };

    return (
        <AppLayout title={t('admin.audit.title')} subtitle={t('admin.audit.subtitle')}>
            <Head title={t('admin.audit.title')} />
            <div className="card p-4 mb-4 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-6 gap-3 items-end">
                <div>
                    <label className="label" htmlFor="f-user">{t('admin.audit.user')}</label>
                    <select id="f-user" className="input w-full" value={f.user_id} onChange={set('user_id')}>
                        <option value="">{t('common.all')}</option>
                        {(filters.data?.users ?? []).map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}
                    </select>
                </div>
                <div>
                    <label className="label" htmlFor="f-model">{t('admin.audit.model')}</label>
                    <select id="f-model" className="input w-full" value={f.subject_type} onChange={set('subject_type')}>
                        <option value="">{t('common.all')}</option>
                        {(filters.data?.subject_types ?? []).map((s) => <option key={s} value={s}>{short(s)}</option>)}
                    </select>
                </div>
                <div>
                    <label className="label" htmlFor="f-event">{t('admin.audit.event')}</label>
                    <select id="f-event" className="input w-full" value={f.event} onChange={set('event')}>
                        <option value="">{t('common.all')}</option>
                        {(filters.data?.events ?? []).map((s) => <option key={s} value={s}>{s}</option>)}
                    </select>
                </div>
                <div>
                    <label className="label" htmlFor="f-from">{t('admin.audit.from')}</label>
                    <input id="f-from" type="date" className="input w-full" value={f.from} onChange={set('from')} />
                </div>
                <div>
                    <label className="label" htmlFor="f-to">{t('admin.audit.to')}</label>
                    <input id="f-to" type="date" className="input w-full" value={f.to} onChange={set('to')} />
                </div>
                <button type="button" className="btn-secondary" onClick={() => { setPage(1); setF({ user_id: '', subject_type: '', event: '', from: '', to: '' }); }}>{t('common.reset')}</button>
            </div>

            {log.error && <div className="card p-4 mb-4 text-sm text-red-700 bg-red-50">{log.error.message}</div>}

            <div className="card overflow-x-auto">
                <table className="table-auto w-full">
                    <thead>
                        <tr>
                            <th>{t('admin.audit.when')}</th><th>{t('admin.audit.user')}</th><th>{t('admin.audit.event')}</th>
                            <th>{t('admin.audit.record')}</th><th>{t('admin.audit.description')}</th><th>{t('admin.audit.changes')}</th>
                        </tr>
                    </thead>
                    <tbody>
                        {log.loading && !log.data && <tr><td colSpan={6}><Loader2 className="w-5 h-5 mx-auto my-8 animate-spin text-slate-500" /></td></tr>}
                        {log.data?.data.length === 0 && <tr><td colSpan={6} className="text-center py-10 text-slate-500 text-sm">{t('admin.audit.empty')}</td></tr>}
                        {log.data?.data.map((e) => (
                            <tr key={e.id}>
                                <td className="text-sm whitespace-nowrap">{formatDateTime(e.created_at)}</td>
                                <td className="text-sm">{e.causer?.name ?? t('admin.audit.system')}</td>
                                <td>{e.event ? <span className="badge-slate">{e.event}</span> : '—'}</td>
                                <td className="text-sm whitespace-nowrap">{short(e.subject_type)}{e.subject_id ? ` #${e.subject_id}` : ''}</td>
                                <td className="text-sm">{e.description}</td>
                                <td className="min-w-[12rem]"><Changes p={e.properties} /></td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            {log.data && log.data.last_page > 1 && (
                <div className="flex items-center justify-between mt-4 text-sm text-slate-600">
                    <button className="btn-secondary btn-sm" disabled={page <= 1} onClick={() => setPage(page - 1)}>{t('common.previous')}</button>
                    <span>{log.data.current_page} / {log.data.last_page}</span>
                    <button className="btn-secondary btn-sm" disabled={page >= log.data.last_page} onClick={() => setPage(page + 1)}>{t('common.next')}</button>
                </div>
            )}
        </AppLayout>
    );
}
