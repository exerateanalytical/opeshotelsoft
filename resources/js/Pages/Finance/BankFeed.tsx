import AppLayout from '@/Layouts/AppLayout';
import QueryStatus from '@/Components/ui/QueryStatus';
import { Head } from '@inertiajs/react';
import { useState } from 'react';
import { api, useApi } from '@/lib/api';
import { useT } from '@/lib/i18n';
import { Banner, errMsg, Field } from './shared';
import { M, Table, Td, Th } from './coreShared';
import { AbNav, uploadFile } from './ab3Shared';

interface Bank { id: number; name: string }
interface Rule { id: number; name: string; priority: number; direction: string; match_mode: string; pattern: string; account_code: string; auto_post: boolean; active: boolean }
interface Sug { line_id: number; date: string; description: string; amount: number; rule: string; account_code: string }
interface Result { import: { rows: number; skipped: number }; rules: { posted: number; suggested: number }; auto_match: unknown }

export default function BankFeed() {
    const t = useT();
    const banks = useApi<Bank[]>('accounting/bank-accounts');
    const rules = useApi<Rule[]>('accounting3/bank-rules');
    const [bank, setBank] = useState<number | null>(null);
    const bankId = bank ?? banks.data?.[0]?.id ?? null;
    const sugs = useApi<Sug[]>(bankId ? `accounting3/bank-accounts/${bankId}/rule-suggestions` : null, [bankId]);
    const [format, setFormat] = useState('mt940');
    const [mapping, setMapping] = useState('{"date":"Date","amount":"Montant","reference":"Ref","description":"Libelle"}');
    const [error, setError] = useState<string | null>(null);
    const [ok, setOk] = useState<string | null>(null);
    const [rule, setRule] = useState({ name: '', pattern: '', account_code: '', direction: 'any', match_mode: 'contains', auto_post: false });

    const run = async (fn: () => Promise<unknown>, msg = t('acc3.saved')) => {
        setError(null); setOk(null);
        try { await fn(); setOk(msg); rules.reload(); sugs.reload(); } catch (e) { setError(errMsg(e)); }
    };

    return (
        <AppLayout title={t('acc3.feed.title')} subtitle={t('acc3.feed.subtitle')}>
            <Head title={t('acc3.feed.title')} />
            <QueryStatus query={banks} variant="table" />
            <AbNav active="/finance/bank-feed" />
            <Banner error={error} success={ok} />

            <div className="card mb-4 p-4">
                <div className="flex flex-wrap items-end gap-2">
                    <Field label={t('acc3.feed.account')}><select className="select" value={bankId ?? ''} onChange={(e) => setBank(Number(e.target.value))}>{banks.data?.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}</select></Field>
                    <Field label={t('acc3.feed.format')}><select className="select" value={format} onChange={(e) => setFormat(e.target.value)}><option value="mt940">MT940</option><option value="camt053">CAMT.053</option><option value="csv">CSV</option></select></Field>
                    <input type="file" className="input w-full sm:w-auto" aria-label={t('acc3.feed.format')} onChange={(e) => {
                        const f = e.target.files?.[0];
                        if (!f || !bankId) return;
                        const fd = new FormData(); fd.append('file', f); fd.append('format', format);
                        if (format === 'csv') fd.append('mapping', mapping);
                        run(async () => {
                            const r = await uploadFile<Result>(`accounting3/bank-accounts/${bankId}/feed`, fd);
                            setOk(t('acc3.feed.imported', { rows: r.import.rows, skipped: r.import.skipped, posted: r.rules.posted, suggested: r.rules.suggested }));
                        }, t('acc3.saved'));
                    }} />
                </div>
                {format === 'csv' && <div className="mt-2"><Field label={t('acc3.feed.mapping')}><input className="input w-full font-mono" value={mapping} onChange={(e) => setMapping(e.target.value)} /></Field></div>}
            </div>

            <div className="card mb-4 p-4">
                <h3 className="mb-3 font-semibold">{t('acc3.feed.suggestions')}</h3>
                <Table head={<><Th>{t('acc3.date')}</Th><Th>{t('acc3.feed.description')}</Th><Th r>{t('acc3.amount')}</Th><Th>{t('acc3.feed.rule')}</Th><Th>{t('acc3.feed.account_code')}</Th><Th /></>}>
                    {sugs.data?.map((s) => (
                        <tr key={s.line_id}><Td>{s.date}</Td><Td>{s.description}</Td><Td r>{M(s.amount)}</Td><Td>{s.rule}</Td><Td mono>{s.account_code}</Td>
                            <Td r><button className="btn-primary" onClick={() => run(() => api(`accounting3/statement-lines/${s.line_id}/accept-rule`, { method: 'POST' }), t('acc3.feed.posted'))}>{t('acc3.feed.accept')}</button></Td></tr>
                    ))}
                </Table>
                {sugs.data?.length === 0 && <p className="py-3 text-sm text-slate-500">{t('acc3.feed.no_suggestions')}</p>}
                {bankId && <button className="btn-secondary mt-3" onClick={() => run(() => api(`accounting3/bank-accounts/${bankId}/apply-rules`, { method: 'POST' }), t('acc3.feed.applied'))}>{t('acc3.feed.apply')}</button>}
            </div>

            <div className="card p-4">
                <h3 className="mb-3 font-semibold">{t('acc3.feed.rules')}</h3>
                <form className="mb-3 grid grid-cols-[repeat(auto-fit,minmax(min(100%,11rem),1fr))] gap-2 [&>*]:min-w-0" onSubmit={(e) => { e.preventDefault(); run(() => api('accounting3/bank-rules', { method: 'POST', body: JSON.stringify(rule) })); }}>
                    <Field label={t('acc3.name')}><input required className="input w-full" value={rule.name} onChange={(e) => setRule({ ...rule, name: e.target.value })} /></Field>
                    <Field label={t('acc3.feed.pattern')}><input required className="input w-full" value={rule.pattern} onChange={(e) => setRule({ ...rule, pattern: e.target.value })} /></Field>
                    <Field label={t('acc3.feed.mode')}><select className="select w-full" value={rule.match_mode} onChange={(e) => setRule({ ...rule, match_mode: e.target.value })}><option value="contains">{t('acc3.feed.contains')}</option><option value="regex">Regex</option></select></Field>
                    <Field label={t('acc3.feed.direction')}><select className="select w-full" value={rule.direction} onChange={(e) => setRule({ ...rule, direction: e.target.value })}><option value="any">{t('acc3.feed.any')}</option><option value="in">{t('acc3.feed.in')}</option><option value="out">{t('acc3.feed.out')}</option></select></Field>
                    <Field label={t('acc3.feed.account_code')}><input required className="input w-full" value={rule.account_code} onChange={(e) => setRule({ ...rule, account_code: e.target.value })} /></Field>
                    <label className="flex min-h-11 items-center gap-2 text-sm"><input type="checkbox" className="h-5 w-5 shrink-0" checked={rule.auto_post} onChange={(e) => setRule({ ...rule, auto_post: e.target.checked })} />{t('acc3.feed.auto_post')}</label>
                    <button className="btn-primary col-span-full">{t('common.save')}</button>
                </form>
                <Table head={<><Th>{t('acc3.name')}</Th><Th>{t('acc3.feed.pattern')}</Th><Th>{t('acc3.feed.direction')}</Th><Th>{t('acc3.feed.account_code')}</Th><Th>{t('acc3.feed.auto_post')}</Th></>}>
                    {rules.data?.map((r) => <tr key={r.id}><Td b>{r.name}</Td><Td mono>{r.pattern}</Td><Td>{r.direction}</Td><Td mono>{r.account_code}</Td><Td>{r.auto_post ? t('acc3.yes') : t('acc3.no')}</Td></tr>)}
                </Table>
            </div>
        </AppLayout>
    );
}
