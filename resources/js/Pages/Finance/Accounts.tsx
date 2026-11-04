import { useState } from 'react';
import EmptyState from '@/Components/ui/EmptyState';
import { NoResults } from '@/Components/ui/EmptyStates';
import QueryStatus from '@/Components/ui/QueryStatus';
import { api, useApi } from '@/lib/api';
import { useT } from '@/lib/i18n';
import { Account, Banner, errMsg, Field } from './shared';
import ExportButtons from '@/Components/ExportButtons';

const TYPES = ['asset', 'liability', 'equity', 'income', 'expense', 'off_balance'];
const blank = { code: '', name: '', type: 'expense', postable: true, reconcilable: false, active: true };

export default function Accounts() {
    const t = useT();
    const list = useApi<Account[]>('accounting/accounts');
    const [q, setQ] = useState('');
    const [cls, setCls] = useState('');
    const [form, setForm] = useState<typeof blank | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [ok, setOk] = useState<string | null>(null);

    const rows = (list.data ?? []).filter(
        (a) => (!cls || String(a.class) === cls) && (!q || a.code.startsWith(q) || a.name.toLowerCase().includes(q.toLowerCase())),
    );

    async function save(e: React.FormEvent) {
        e.preventDefault();
        if (!form) return;
        setError(null); setOk(null);
        try {
            await api('accounting/accounts', { method: 'PUT', body: JSON.stringify(form) });
            setOk(t('fin.accounts.saved', { code: form.code }));
            setForm(null);
            list.reload();
        } catch (err) { setError(errMsg(err)); }
    }

    return (
        <div>
            <Banner error={error ?? list.error?.message} success={ok} />
            <div className="mb-4 flex flex-wrap items-end gap-2">
                <input className="input w-full sm:w-64" placeholder={t('fin.accounts.search')} aria-label={t('fin.accounts.search')} value={q} onChange={(e) => setQ(e.target.value)} />
                <select className="select w-full sm:w-40" value={cls} onChange={(e) => setCls(e.target.value)} aria-label={t('fin.accounts.all_classes')}>
                    <option value="">{t('fin.accounts.all_classes')}</option>
                    {[1, 2, 3, 4, 5, 6, 7, 8, 9].map((c) => <option key={c} value={c}>{t('fin.accounts.class_n', { n: c })}</option>)}
                </select>
                <div className="sm:ml-auto flex flex-wrap gap-2">
                    <ExportButtons report="chart-of-accounts" params={{ q, class: cls }} />
                    <button className="btn-primary" onClick={() => setForm({ ...blank })}>{t('fin.accounts.new')}</button>
                </div>
            </div>
            {form && (
                <form onSubmit={save} className="card mb-4 grid grid-cols-1 gap-3 p-4 sm:grid-cols-3 [&>*]:min-w-0">
                    <Field label={t('fin.col.code')}><input className="input w-full" value={form.code} onChange={(e) => setForm({ ...form, code: e.target.value })} required pattern="[1-9][0-9]{1,19}" /></Field>
                    <Field label={t('fin.col.name')}><input className="input w-full" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} required /></Field>
                    <Field label={t('fin.col.type')}>
                        <select className="select w-full" value={form.type} onChange={(e) => setForm({ ...form, type: e.target.value })}>
                            {TYPES.map((ty) => <option key={ty} value={ty}>{t(`fin.account_type.${ty}`)}</option>)}
                        </select>
                    </Field>
                    <div className="flex flex-wrap gap-4 text-sm sm:col-span-3">
                        {(['postable', 'reconcilable', 'active'] as const).map((k) => (
                            <label key={k} className="flex items-center gap-1">
                                <input type="checkbox" className="h-5 w-5 shrink-0" checked={form[k]} onChange={(e) => setForm({ ...form, [k]: e.target.checked })} /> {t(`fin.accounts.flag.${k}`)}
                            </label>
                        ))}
                    </div>
                    <div className="flex gap-2 sm:col-span-3">
                        <button className="btn-primary">{t('common.save')}</button>
                        <button type="button" className="btn-secondary" onClick={() => setForm(null)}>{t('common.cancel')}</button>
                    </div>
                </form>
            )}
            <div className="card overflow-x-auto">
                <QueryStatus query={list} variant="table" />
                <table className="table-auto w-full text-sm">
                    <thead><tr className="text-left text-slate-500"><th className="p-2">{t('fin.col.code')}</th><th className="p-2">{t('fin.col.name')}</th><th className="p-2">{t('fin.col.class')}</th><th className="p-2">{t('fin.col.type')}</th><th className="p-2">{t('fin.accounts.flags')}</th><th /></tr></thead>
                    <tbody>
                        {rows.map((a) => (
                            <tr key={a.id} className="border-t border-slate-100">
                                <td className="p-2 font-mono">{a.code}</td>
                                <td className="p-2">{a.name}</td>
                                <td className="p-2">{a.class}</td>
                                <td className="p-2">{t(`fin.account_type.${a.type}`)}</td>
                                <td className="p-2 text-xs text-slate-500">{[a.postable && t('fin.accounts.flag.postable'), a.reconcilable && t('fin.accounts.flag.reconcilable'), !a.active && t('fin.accounts.flag.inactive')].filter(Boolean).join(', ')}</td>
                                <td className="p-2 text-right">
                                    <button className="btn-secondary btn-sm" onClick={() => setForm({ code: a.code, name: a.name, type: a.type, postable: a.postable, reconcilable: a.reconcilable, active: a.active })}>{t('common.edit')}</button>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
                {list.data && rows.length === 0 && (q || cls
                    ? <NoResults onReset={() => { setQ(''); setCls(''); }} />
                    : <EmptyState title={t('states3.empty.accounts.title')} description={t('states3.empty.accounts.text')} action={<button type="button" className="btn-primary" onClick={() => setForm({ ...blank })}>{t('fin.accounts.new')}</button>} />)}
            </div>
        </div>
    );
}
