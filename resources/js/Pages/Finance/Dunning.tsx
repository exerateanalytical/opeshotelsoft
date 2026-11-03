import AppLayout from '@/Layouts/AppLayout';
import { Head } from '@inertiajs/react';
import { useState } from 'react';
import { api, useApi } from '@/lib/api';
import { useT } from '@/lib/i18n';
import { Banner, errMsg, Field, Loading } from './shared';
import { M, Table, Td, Th } from './coreShared';
import { AbNav } from './ab3Shared';

interface Cand { invoice_id: number; number: string; company_id: number | null; bill_to: string; days_overdue: number; balance: number; fee: number; interest: number; last_level_sent: number; next_level: number | null; held: boolean; eligible: boolean }
interface Level { level: number; days_overdue: number; fee: number; active: boolean }
interface Settings { settings: { interest_enabled: boolean; interest_rate: number; channels: string[] }; levels: Level[] }
interface Notice { id: number; invoice_id: number; level: number; created_at: string; status: string; balance: number; fee: number; channels: { channel: string }[] }

export default function Dunning() {
    const t = useT();
    const cands = useApi<Cand[]>('accounting3/dunning/candidates');
    const cfg = useApi<Settings>('accounting3/dunning/settings');
    const notices = useApi<Notice[]>('accounting3/dunning/notices');
    const [error, setError] = useState<string | null>(null);
    const [ok, setOk] = useState<string | null>(null);
    const [locale, setLocale] = useState('fr');
    const [sel, setSel] = useState<number[]>([]);

    const run = async (fn: () => Promise<unknown>, msg = t('acc3.saved')) => {
        setError(null); setOk(null);
        try { await fn(); setOk(msg); cands.reload(); cfg.reload(); notices.reload(); setSel([]); } catch (e) { setError(errMsg(e)); }
    };

    return (
        <AppLayout title={t('acc3.dun.title')} subtitle={t('acc3.dun.subtitle')}>
            <Head title={t('acc3.dun.title')} />
            <AbNav active="/finance/dunning" />
            <Banner error={error ?? cands.error?.message} success={ok} />

            <div className="card mb-4 p-4">
                <div className="mb-3 flex flex-wrap items-end gap-2">
                    <Field label={t('acc3.dun.language')}><select className="input" value={locale} onChange={(e) => setLocale(e.target.value)}><option value="fr">Français</option><option value="en">English</option></select></Field>
                    <button className="btn-primary" onClick={() => run(() => api('accounting3/dunning/send', { method: 'POST', body: JSON.stringify({ invoice_ids: sel.length ? sel : null, locale }) }), t('acc3.dun.sent'))}>{sel.length ? t('acc3.dun.send_selected') : t('acc3.dun.send_all')}</button>
                </div>
                <Loading on={cands.loading} />
                <Table head={<><Th /><Th>{t('acc3.dun.invoice')}</Th><Th>{t('acc3.dun.customer')}</Th><Th r>{t('acc3.dun.days')}</Th><Th r>{t('acc3.dun.balance')}</Th><Th>{t('acc3.dun.next')}</Th><Th r>{t('acc3.dun.fee')}</Th><Th r>{t('acc3.dun.interest')}</Th><Th /></>}>
                    {cands.data?.map((c) => (
                        <tr key={c.invoice_id}>
                            <Td><input type="checkbox" className="h-5 w-5" aria-label={c.number} disabled={!c.eligible} checked={sel.includes(c.invoice_id)} onChange={(e) => setSel(e.target.checked ? [...sel, c.invoice_id] : sel.filter((x) => x !== c.invoice_id))} /></Td>
                            <Td mono>{c.number}</Td><Td>{c.bill_to}</Td><Td r>{c.days_overdue}</Td><Td r>{M(c.balance)}</Td>
                            <Td>{c.held ? t('acc3.dun.held') : c.next_level ? `${t('acc3.dun.level')} ${c.next_level}${c.eligible ? '' : ` (${t('acc3.dun.already')})`}` : '-'}</Td>
                            <Td r>{M(c.fee)}</Td><Td r>{M(c.interest)}</Td>
                            <Td r>
                                {c.held
                                    ? null
                                    : <button className="btn-secondary" onClick={() => run(() => api('accounting3/dunning/holds', { method: 'POST', body: JSON.stringify({ invoice_id: c.invoice_id, reason: t('acc3.dun.hold_reason') }) }))}>{t('acc3.dun.stop')}</button>}
                            </Td>
                        </tr>
                    ))}
                </Table>
                {cands.data?.length === 0 && <p className="py-3 text-sm text-slate-500">{t('acc3.dun.none')}</p>}
            </div>

            <div className="grid gap-4 lg:grid-cols-2 [&>*]:min-w-0">
                <div className="card p-4">
                    <h3 className="mb-3 font-semibold">{t('acc3.dun.levels')}</h3>
                    <Table head={<><Th>{t('acc3.dun.level')}</Th><Th r>{t('acc3.dun.days')}</Th><Th r>{t('acc3.dun.fee')}</Th></>}>
                        {cfg.data?.levels.map((l) => (
                            <tr key={l.level}><Td b>{l.level}</Td>
                                <Td r><input type="number" aria-label={t('acc3.dun.days')} className="input w-20 text-right" defaultValue={l.days_overdue} onBlur={(e) => run(() => api(`accounting3/dunning/levels/${l.level}`, { method: 'PUT', body: JSON.stringify({ days_overdue: Number(e.target.value) }) }))} /></Td>
                                <Td r><input type="number" aria-label={t('acc3.dun.fee')} className="input w-28 text-right" defaultValue={l.fee} onBlur={(e) => run(() => api(`accounting3/dunning/levels/${l.level}`, { method: 'PUT', body: JSON.stringify({ fee: Number(e.target.value) }) }))} /></Td></tr>
                        ))}
                    </Table>
                    {cfg.data && (
                        <div className="mt-3 flex flex-wrap items-end gap-3">
                            <label className="flex items-center gap-2 text-sm"><input type="checkbox" className="h-5 w-5" checked={cfg.data.settings.interest_enabled} onChange={(e) => run(() => api('accounting3/dunning/settings', { method: 'PUT', body: JSON.stringify({ interest_enabled: e.target.checked }) }))} />{t('acc3.dun.interest_on')}</label>
                            <Field label={t('acc3.dun.rate')}><input type="number" step="0.1" className="input w-24" defaultValue={cfg.data.settings.interest_rate} onBlur={(e) => run(() => api('accounting3/dunning/settings', { method: 'PUT', body: JSON.stringify({ interest_rate: Number(e.target.value) }) }))} /></Field>
                        </div>
                    )}
                    <p className="mt-2 text-xs text-slate-500">{t('acc3.dun.note')}</p>
                </div>
                <div className="card p-4">
                    <h3 className="mb-3 font-semibold">{t('acc3.dun.log')}</h3>
                    <Table head={<><Th>{t('acc3.date')}</Th><Th>{t('acc3.dun.invoice')}</Th><Th>{t('acc3.dun.level')}</Th><Th>{t('acc3.dun.channels')}</Th></>}>
                        {notices.data?.slice(0, 30).map((n) => <tr key={n.id}><Td>{n.created_at.slice(0, 10)}</Td><Td mono>#{n.invoice_id}</Td><Td>{n.level}</Td><Td>{n.status === 'no_contact' ? t('acc3.dun.no_contact') : n.channels.map((c) => c.channel).join(', ')}</Td></tr>)}
                    </Table>
                </div>
            </div>
        </AppLayout>
    );
}
