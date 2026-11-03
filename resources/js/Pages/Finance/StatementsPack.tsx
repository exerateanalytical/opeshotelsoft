import { cn } from '@/lib/utils';
import AppLayout from '@/Layouts/AppLayout';
import { Head } from '@inertiajs/react';
import { useState } from 'react';
import { api, useApi } from '@/lib/api';
import { useT } from '@/lib/i18n';
import { Banner, errMsg, Field, Loading, today } from './shared';
import { M, Table, Td, Th, yearStart } from './coreShared';
import { AbNav, Gate, downloadFile } from './ab3Shared';

interface Pack {
    meta: { period_from: string; period_to: string; gate: string };
    bilan: { actif: { ref: string; label: string; brut: number; amort: number; net: number; net_prev: number }[]; passif: { ref: string; label: string; amount: number; amount_prev: number }[]; total_actif: number; total_passif: number; balanced: boolean };
    resultat: { lines: { ref: string; label: string; side: string; amount: number }[]; balances: { ref: string; label: string; amount: number }[]; net_result: number; check: boolean };
    tafire: { lines: { label: string; amount: number; subtotal: boolean }[]; check: boolean };
    notes: { note: string; title: string; status: string; rows: unknown[]; prompt: string | null }[];
}
interface Saved { id: number; period_from: string; period_to: string; status: string; hash: string }

export default function StatementsPack() {
    const t = useT();
    const [from, setFrom] = useState(yearStart());
    const [to, setTo] = useState(today());
    const [tab, setTab] = useState<'bilan' | 'resultat' | 'tafire' | 'notes'>('bilan');
    const pack = useApi<Pack>(`accounting3/close/statements?from=${from}&to=${to}`, [from, to]);
    const saved = useApi<Saved[]>('accounting3/close/statement-packs');
    const [error, setError] = useState<string | null>(null);
    const [ok, setOk] = useState<string | null>(null);
    const p = pack.data;

    const run = async (fn: () => Promise<unknown>, msg: string) => {
        setError(null); setOk(null);
        try { await fn(); setOk(msg); saved.reload(); } catch (e) { setError(errMsg(e)); }
    };

    return (
        <AppLayout title={t('acc3.st.title')} subtitle={t('acc3.st.subtitle')}>
            <Head title={t('acc3.st.title')} />
            <AbNav active="/finance/statements-pack" />
            <Gate>{t('acc3.st.gate')}</Gate>
            <Banner error={error ?? pack.error?.message} success={ok} />

            <div className="card mb-4 p-4">
                <div className="flex flex-wrap items-end gap-2">
                    <Field label={t('acc3.from')}><input type="date" className="input" value={from} onChange={(e) => setFrom(e.target.value)} /></Field>
                    <Field label={t('acc3.to')}><input type="date" className="input" value={to} onChange={(e) => setTo(e.target.value)} /></Field>
                    <button className="btn-secondary" onClick={() => downloadFile(`accounting3/close/statements?from=${from}&to=${to}&format=xlsx`, `syscohada-${from}-${to}.xlsx`).catch((e) => setError(errMsg(e)))}>Excel</button>
                    <button className="btn-primary" onClick={() => run(() => api('accounting3/close/statement-packs', { method: 'POST', body: JSON.stringify({ from, to }) }), t('acc3.st.saved'))}>{t('acc3.st.save')}</button>
                </div>
                {p && <p className={`mt-2 text-sm ${p.bilan.balanced && p.resultat.check ? 'text-emerald-700' : 'text-red-700'}`}>{p.bilan.balanced && p.resultat.check ? t('acc3.st.balanced') : t('acc3.st.unbalanced')}</p>}
            </div>

            <div className="tabs mb-3">
                {(['bilan', 'resultat', 'tafire', 'notes'] as const).map((k) => (
                    <button key={k} onClick={() => setTab(k)} className={cn('tab', tab === k && 'active')}>{t(`acc3.st.tab.${k}`)}</button>
                ))}
            </div>
            <Loading on={pack.loading} />

            {p && tab === 'bilan' && (
                <div className="grid gap-4 lg:grid-cols-2 [&>*]:min-w-0">
                    <div className="card p-4">
                        <h3 className="mb-3 font-semibold">{t('acc3.st.assets')}</h3>
                        <Table head={<><Th>{t('acc3.st.ref')}</Th><Th /><Th r>{t('acc3.st.gross')}</Th><Th r>{t('acc3.st.amort')}</Th><Th r>{t('acc3.st.net')}</Th></>}>
                            {p.bilan.actif.map((l) => <tr key={l.ref}><Td mono>{l.ref}</Td><Td>{l.label}</Td><Td r>{M(l.brut)}</Td><Td r>{M(l.amort)}</Td><Td r b>{M(l.net)}</Td></tr>)}
                            <tr><Td /><Td b>{t('acc3.total')}</Td><Td /><Td /><Td r b>{M(p.bilan.total_actif)}</Td></tr>
                        </Table>
                    </div>
                    <div className="card p-4">
                        <h3 className="mb-3 font-semibold">{t('acc3.st.liabilities')}</h3>
                        <Table head={<><Th>{t('acc3.st.ref')}</Th><Th /><Th r>{t('acc3.amount')}</Th></>}>
                            {p.bilan.passif.map((l) => <tr key={l.ref}><Td mono>{l.ref}</Td><Td>{l.label}</Td><Td r b>{M(l.amount)}</Td></tr>)}
                            <tr><Td /><Td b>{t('acc3.total')}</Td><Td r b>{M(p.bilan.total_passif)}</Td></tr>
                        </Table>
                    </div>
                </div>
            )}
            {p && tab === 'resultat' && (
                <div className="card p-4">
                    <Table head={<><Th>{t('acc3.st.ref')}</Th><Th /><Th r>{t('acc3.amount')}</Th></>}>
                        {p.resultat.lines.filter((l) => l.amount !== 0).map((l) => <tr key={l.ref}><Td mono>{l.ref}</Td><Td>{l.label}</Td><Td r>{M(l.amount)}</Td></tr>)}
                        {p.resultat.balances.map((l) => <tr key={l.ref} className="bg-slate-50"><Td mono b>{l.ref}</Td><Td b>{l.label}</Td><Td r b>{M(l.amount)}</Td></tr>)}
                    </Table>
                </div>
            )}
            {p && tab === 'tafire' && (
                <div className="card p-4">
                    <Table head={<><Th /><Th r>{t('acc3.amount')}</Th></>}>
                        {p.tafire.lines.map((l) => <tr key={l.label}><Td b={l.subtotal}>{l.label}</Td><Td r b={l.subtotal}>{M(l.amount)}</Td></tr>)}
                    </Table>
                </div>
            )}
            {p && tab === 'notes' && (
                <div className="card p-4">
                    <Table head={<><Th>{t('acc3.st.note')}</Th><Th>{t('acc3.name')}</Th><Th>{t('acc3.status')}</Th><Th>{t('acc3.st.detail')}</Th></>}>
                        {p.notes.map((n) => <tr key={n.note}><Td mono>{n.note}</Td><Td>{n.title}</Td><Td>{t(`acc3.st.note.${n.status}`)}</Td><Td>{n.status === 'auto' ? t('acc3.st.rows', { count: n.rows.length }) : n.prompt}</Td></tr>)}
                    </Table>
                </div>
            )}

            <div className="card mt-4 p-4">
                <h3 className="mb-3 font-semibold">{t('acc3.st.history')}</h3>
                <Table head={<><Th>{t('acc3.pay.period')}</Th><Th>{t('acc3.status')}</Th><Th>Hash</Th><Th /></>}>
                    {saved.data?.map((s) => (
                        <tr key={s.id}><Td>{s.period_from} / {s.period_to}</Td><Td>{t(`acc3.st.status.${s.status}`)}</Td><Td mono>{s.hash.slice(0, 12)}</Td>
                            <Td r>
                                {s.status === 'draft' && <button className="btn-secondary mr-1" onClick={() => run(() => api(`accounting3/close/statement-packs/${s.id}/finalize`, { method: 'POST' }), t('acc3.st.finalized'))}>{t('acc3.st.finalize')}</button>}
                                <button className="btn-secondary" onClick={() => downloadFile(`accounting3/close/statement-packs/${s.id}?format=xlsx`, `syscohada-pack-${s.id}.xlsx`).catch((e) => setError(errMsg(e)))}>Excel</button>
                            </Td></tr>
                    ))}
                </Table>
            </div>
        </AppLayout>
    );
}
