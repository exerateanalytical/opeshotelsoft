import AppLayout from '@/Layouts/AppLayout';
import { Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import { api, ApiError, useApi } from '@/lib/api';
import { useMt } from './strings';

interface Tpl { id: number; journey: string; name: string; locale: string; category: string; body: string; meta_status: string; meta_template_id: string | null; rejected_reason: string | null }

const STATUSES = ['draft', 'submitted', 'approved', 'rejected', 'paused'];

export default function MessagingTemplates() {
    const t = useMt();
    const reg = useApi<{ dry_run: boolean; journey_enabled: boolean; templates: Tpl[] }>('messaging/templates');
    const [edit, setEdit] = useState<Record<number, Partial<Tpl>>>({});
    const [error, setError] = useState('');

    async function save(id: number) {
        setError('');
        try {
            await api(`messaging/templates/${id}`, { method: 'PUT', body: JSON.stringify(edit[id]) });
            setEdit((e) => { const n = { ...e }; delete n[id]; return n; });
            reg.reload();
        } catch (e) { setError(e instanceof ApiError ? e.message : String(e)); }
    }
    const set = (id: number, patch: Partial<Tpl>) => setEdit((e) => ({ ...e, [id]: { ...e[id], ...patch } }));

    return (
        <AppLayout title={t('messaging.templates.title')} subtitle={t('messaging.templates.subtitle')}
            actions={<Link href="/messaging" className="btn-secondary">{t('messaging.inbox.title')}</Link>}>
            <Head title={t('messaging.templates.title')} />
            {reg.data?.dry_run && <p className="mb-2 text-sm text-slate-600">{t('messaging.dry_run')}</p>}
            {reg.data && !reg.data.journey_enabled && <p className="mb-2 text-sm text-slate-600">{t('messaging.journey_disabled')}</p>}
            {error && <p className="mb-2 text-sm text-red-700">{error}</p>}
            <div className="card overflow-x-auto">
                <table className="table-auto">
                    <thead><tr><th>{t('messaging.journey')}</th><th>{t('messaging.name')}</th><th>{t('messaging.locale')}</th><th>{t('messaging.body')}</th><th>{t('messaging.meta_status')}</th><th /></tr></thead>
                    <tbody>
                        {reg.data?.templates.map((x) => {
                            const e = edit[x.id] ?? {};
                            return (
                                <tr key={x.id}>
                                    <td>{t(`messaging.journey.${x.journey}`)}</td>
                                    <td className="font-mono text-sm">{x.name}</td>
                                    <td>{x.locale.toUpperCase()}</td>
                                    <td><textarea className="input min-w-[280px]" rows={3} value={e.body ?? x.body} onChange={(ev) => set(x.id, { body: ev.target.value })} /></td>
                                    <td>
                                        <select className="input" value={e.meta_status ?? x.meta_status} onChange={(ev) => set(x.id, { meta_status: ev.target.value })}>
                                            {STATUSES.map((s) => <option key={s} value={s}>{t(`messaging.status.${s}`)}</option>)}
                                        </select>
                                        {x.rejected_reason && <div className="text-xs text-red-700">{x.rejected_reason}</div>}
                                    </td>
                                    <td><button type="button" className="btn-secondary" disabled={!edit[x.id]} onClick={() => save(x.id)}>{t('messaging.save')}</button></td>
                                </tr>
                            );
                        })}
                    </tbody>
                </table>
            </div>
        </AppLayout>
    );
}
