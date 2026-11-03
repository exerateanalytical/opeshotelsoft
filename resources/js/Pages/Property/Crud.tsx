import { Loader2, Pencil, Plus, Search, Trash2, X } from 'lucide-react';
import { useState } from 'react';
import { api, ApiError, useApi } from '@/lib/api';
import { formatCurrency, formatDate } from '@/lib/utils';
import { useT } from '@/lib/i18n';

export type FieldType = 'text' | 'number' | 'money' | 'bool' | 'select' | 'date' | 'textarea' | 'list';
export interface Field {
    name: string;
    label: string;
    type?: FieldType;
    options?: [string, string][];
    required?: boolean;
    nullable?: boolean;
}
export interface Column {
    key: string;
    label: string;
    render?: (row: Row) => React.ReactNode;
    type?: FieldType;
}
export type Row = Record<string, any> & { id?: number };
export interface Page<T> { data: T[]; total: number; current_page: number; last_page: number }

export function Banner({ error, success }: { error?: string | null; success?: string | null }) {
    return (
        <>
            {error && <div className="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-2 text-sm text-red-700">{error}</div>}
            {success && <div className="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-2 text-sm text-emerald-700">{success}</div>}
        </>
    );
}

export function errMsg(e: unknown): string {
    if (e instanceof ApiError) {
        const errs = e.problem.errors ? Object.values(e.problem.errors).flat().join(' ') : '';
        return errs ? `${e.message}: ${errs}` : e.message;
    }
    return String(e);
}

export function cell(row: Row, c: Column): React.ReactNode {
    if (c.render) return c.render(row);
    const v = row[c.key];
    if (v === null || v === undefined || v === '') return <span className="text-slate-500">-</span>;
    if (c.type === 'money') return formatCurrency(Number(v));
    if (c.type === 'date') return formatDate(v);
    if (c.type === 'bool' || typeof v === 'boolean')
        return <YesNo v={!!v} />;
    if (Array.isArray(v)) return v.join(', ');
    return String(v);
}

function YesNo({ v }: { v: boolean }) {
    const t = useT();
    return v ? <span className="badge-green">{t('prop.crud.yes')}</span> : <span className="badge-slate">{t('prop.crud.no')}</span>;
}

/** Converts form strings into the JSON body the API validates. */
export function toBody(fields: Field[], form: Record<string, any>, editing: boolean): Record<string, unknown> {
    const out: Record<string, unknown> = {};
    for (const f of fields) {
        const v = form[f.name];
        if (f.type === 'bool') { out[f.name] = !!v; continue; }
        if (v === undefined || v === '' || v === null) {
            if (f.nullable && editing) out[f.name] = null;
            continue;
        }
        if (f.type === 'number' || f.type === 'money') out[f.name] = Number(v);
        else if (f.type === 'list') out[f.name] = String(v).split(',').map((s) => s.trim()).filter(Boolean);
        else out[f.name] = v;
    }
    return out;
}

export function fromRow(fields: Field[], row: Row | null): Record<string, any> {
    const out: Record<string, any> = {};
    for (const f of fields) {
        const v = row?.[f.name];
        if (f.type === 'bool') out[f.name] = row ? !!v : f.name === 'active' || f.name === 'refundable' || f.name === 'public';
        else if (f.type === 'list') out[f.name] = Array.isArray(v) ? v.join(', ') : '';
        else if (f.type === 'date') out[f.name] = v ? String(v).slice(0, 10) : '';
        else out[f.name] = v ?? '';
    }
    return out;
}

export function FormFields({ fields, form, set }: { fields: Field[]; form: Record<string, any>; set: (k: string, v: any) => void }) {
    const t = useT();
    return (
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3 [&>*]:min-w-0">
            {fields.map((f) => (
                <label key={f.name} className={f.type === 'textarea' ? 'sm:col-span-2 lg:col-span-3 block' : 'block'}>
                    {f.type === 'bool' ? (
                        <span className="flex items-center gap-2 pt-6 text-sm text-slate-700">
                            <input type="checkbox" className="h-5 w-5 shrink-0" checked={!!form[f.name]} onChange={(e) => set(f.name, e.target.checked)} /> {f.label}
                        </span>
                    ) : (
                        <>
                            <span className="mb-1 block text-xs font-medium text-slate-500">{f.label}{f.required && ' *'}</span>
                            {f.type === 'select' ? (
                                <select className="input" value={form[f.name] ?? ''} onChange={(e) => set(f.name, e.target.value)}>
                                    {!f.required && <option value="">-</option>}
                                    {f.options?.map(([v, l]) => <option key={v} value={v}>{l}</option>)}
                                </select>
                            ) : f.type === 'textarea' ? (
                                <textarea className="input" rows={2} value={form[f.name] ?? ''} onChange={(e) => set(f.name, e.target.value)} />
                            ) : (
                                <input
                                    className="input"
                                    type={f.type === 'number' || f.type === 'money' ? 'number' : f.type === 'date' ? 'date' : 'text'}
                                    step="any"
                                    placeholder={f.type === 'list' ? t('prop.crud.comma_separated') : f.type === 'money' ? 'FCFA' : undefined}
                                    value={form[f.name] ?? ''}
                                    onChange={(e) => set(f.name, e.target.value)}
                                />
                            )}
                        </>
                    )}
                </label>
            ))}
        </div>
    );
}

interface CrudProps {
    path: string;
    noun: string;
    columns: Column[];
    fields: Field[];
    filters?: { name: string; label: string; options: [string, string][] }[];
    deleteLabel?: string;
    extra?: (reload: () => void) => React.ReactNode;
}

/** List with search/filters/paging plus inline create/edit/deactivate for an apiResource endpoint. */
export default function CrudTab({ path, noun, columns, fields, filters = [], deleteLabel: deleteLabelProp, extra }: CrudProps) {
    const t = useT();
    const deleteLabel = deleteLabelProp ?? t('prop.crud.deactivate');
    const [q, setQ] = useState('');
    const [page, setPage] = useState(1);
    const [flt, setFlt] = useState<Record<string, string>>({});
    const [editing, setEditing] = useState<Row | null | undefined>(undefined);
    const [form, setForm] = useState<Record<string, any>>({});
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [success, setSuccess] = useState<string | null>(null);

    const params = new URLSearchParams({ page: String(page), per_page: '25' });
    if (q) params.set('q', q);
    Object.entries(flt).forEach(([k, v]) => v && params.set(`filter[${k}]`, v));
    const list = useApi<Page<Row>>(`${path}?${params}`);

    function open(row: Row | null) {
        setEditing(row);
        setForm(fromRow(fields, row));
        setError(null);
        setSuccess(null);
    }

    async function save(e: React.FormEvent) {
        e.preventDefault();
        setBusy(true);
        setError(null);
        try {
            const body = toBody(fields, form, !!editing);
            if (editing?.id) await api(`${path}/${editing.id}`, { method: 'PUT', body: JSON.stringify(body) });
            else await api(path, { method: 'POST', body: JSON.stringify(body) });
            setSuccess(editing ? t('prop.crud.updated', { noun }) : t('prop.crud.created', { noun }));
            setEditing(undefined);
            list.reload();
        } catch (err) {
            setError(errMsg(err));
        } finally {
            setBusy(false);
        }
    }

    async function remove(row: Row) {
        if (!confirm(t('prop.crud.confirm_remove', { action: deleteLabel, noun: noun.toLowerCase() }))) return;
        setError(null);
        try {
            await api(`${path}/${row.id}`, { method: 'DELETE' });
            setSuccess(t('prop.crud.removed', { noun, action: deleteLabel.toLowerCase() }));
            list.reload();
        } catch (err) {
            setError(errMsg(err));
        }
    }

    const rows = list.data?.data ?? [];

    return (
        <div>
            <Banner error={error ?? list.error?.message} success={success} />
            <div className="mb-4 flex flex-wrap items-center gap-3">
                <div className="relative min-w-[200px] flex-1">
                    <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-500" />
                    <input className="input pl-9" placeholder={t('prop.crud.search', { noun: noun.toLowerCase() })} aria-label={t('prop.crud.search', { noun: noun.toLowerCase() })} value={q} onChange={(e) => (setQ(e.target.value), setPage(1))} />
                </div>
                {filters.map((f) => (
                    <select key={f.name} className="input w-full sm:w-auto sm:max-w-xs" value={flt[f.name] ?? ''} onChange={(e) => (setFlt({ ...flt, [f.name]: e.target.value }), setPage(1))} aria-label={t('prop.crud.filter_all', { label: f.label })}>
                        <option value="">{t('prop.crud.filter_all', { label: f.label })}</option>
                        {f.options.map(([v, l]) => <option key={v} value={v}>{l}</option>)}
                    </select>
                ))}
                <button className="btn-primary" onClick={() => open(null)}><Plus className="h-4 w-4" /> {t('prop.crud.new', { noun: noun.toLowerCase() })}</button>
            </div>

            {extra?.(list.reload)}

            <p className="mb-3 text-xs text-slate-500">{t('prop.crud.inactive_hint_before', { noun: noun.toLowerCase() })}<strong>{t('prop.crud.active')}</strong>{t('prop.crud.inactive_hint_after')}</p>
            {editing !== undefined && (
                <form onSubmit={save} className="card mb-4 p-4">
                    <div className="mb-3 flex items-center justify-between">
                        <h3 className="font-semibold text-slate-800">{editing ? t('prop.crud.edit', { noun: noun.toLowerCase() }) : t('prop.crud.new', { noun: noun.toLowerCase() })}</h3>
                        <button type="button" className="text-slate-500 hover:text-slate-600" onClick={() => setEditing(undefined)}><X className="h-4 w-4" /></button>
                    </div>
                    <FormFields fields={fields} form={form} set={(k, v) => setForm((f) => ({ ...f, [k]: v }))} />
                    <div className="mt-4 flex gap-2">
                        <button className="btn-primary" disabled={busy}>{busy && <Loader2 className="h-4 w-4 animate-spin" />} {t('common.save')}</button>
                        <button type="button" className="btn-secondary" onClick={() => setEditing(undefined)}>{t('common.cancel')}</button>
                    </div>
                </form>
            )}

            <div className="card overflow-x-auto">
                <table className="table-auto w-full text-sm">
                    <thead>
                        <tr className="text-left text-xs uppercase text-slate-500">
                            {columns.map((c) => <th key={c.key} className="px-3 py-2">{c.label}</th>)}
                            <th className="px-3 py-2" />
                        </tr>
                    </thead>
                    <tbody>
                        {list.loading && !rows.length && (
                            <tr><td colSpan={columns.length + 1} className="px-3 py-6 text-center text-slate-500"><Loader2 className="inline h-4 w-4 animate-spin" /> {t('common.loading')}</td></tr>
                        )}
                        {!list.loading && !rows.length && (
                            <tr><td colSpan={columns.length + 1} className="px-3 py-6 text-center text-slate-500">{t('prop.crud.none', { noun: noun.toLowerCase() })}</td></tr>
                        )}
                        {rows.map((r) => (
                            <tr key={r.id} className="border-t border-slate-100">
                                {columns.map((c) => <td key={c.key} className="whitespace-nowrap px-3 py-2">{cell(r, c)}</td>)}
                                <td className="whitespace-nowrap px-3 py-2 text-right">
                                    <button className="btn-ghost btn-icon" title={t('common.edit')} onClick={() => open(r)} aria-label={t('common.edit')}><Pencil className="h-4 w-4" /></button>
                                    <button className="btn-ghost btn-icon text-red-700" title={deleteLabel} onClick={() => remove(r)} aria-label={deleteLabel}><Trash2 className="h-4 w-4" /></button>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
            {list.data && list.data.last_page > 1 && (
                <div className="mt-3 flex items-center justify-between text-sm text-slate-500">
                    <span>{t('prop.crud.total', { count: list.data.total })}</span>
                    <div className="flex gap-2">
                        <button className="btn-secondary" disabled={page <= 1} onClick={() => setPage(page - 1)}>{t('common.previous')}</button>
                        <span className="self-center">{t('prop.crud.page', { page: list.data.current_page, last: list.data.last_page })}</span>
                        <button className="btn-secondary" disabled={page >= list.data.last_page} onClick={() => setPage(page + 1)}>{t('common.next')}</button>
                    </div>
                </div>
            )}
        </div>
    );
}

/** List plus upsert-by-code for FrontOffice setup/{kind}. */
export function SetupTab({ kind, noun, columns, fields }: { kind: string; noun: string; columns: Column[]; fields: Field[] }) {
    const t = useT();
    const list = useApi<Row[]>(`setup/${kind}`);
    const [q, setQ] = useState('');
    const [editing, setEditing] = useState<Row | null | undefined>(undefined);
    const [form, setForm] = useState<Record<string, any>>({});
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [success, setSuccess] = useState<string | null>(null);

    function open(row: Row | null) {
        setEditing(row);
        setForm(fromRow(fields, row));
        setError(null);
        setSuccess(null);
    }

    async function save(e: React.FormEvent) {
        e.preventDefault();
        setBusy(true);
        setError(null);
        try {
            await api(`setup/${kind}`, { method: 'PUT', body: JSON.stringify(toBody(fields, form, false)) });
            setSuccess(t('prop.crud.saved', { noun, code: form.code }));
            setEditing(undefined);
            list.reload();
        } catch (err) {
            setError(errMsg(err));
        } finally {
            setBusy(false);
        }
    }

    const rows = (Array.isArray(list.data) ? list.data : []).filter((r) => !q || `${r.code} ${r.name}`.toLowerCase().includes(q.toLowerCase()));

    return (
        <div>
            <Banner error={error ?? list.error?.message} success={success} />
            <div className="mb-4 flex flex-wrap items-center gap-3">
                <div className="relative min-w-[200px] flex-1">
                    <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-500" />
                    <input className="input pl-9" placeholder={t('prop.crud.filter_ph')} aria-label={t('prop.crud.filter_ph')} value={q} onChange={(e) => setQ(e.target.value)} />
                </div>
                <button className="btn-primary" onClick={() => open(null)}><Plus className="h-4 w-4" /> {t('prop.crud.new', { noun: noun.toLowerCase() })}</button>
            </div>
            <p className="mb-3 text-xs text-slate-500">{t('prop.crud.inactive_hint_before', { noun: noun.toLowerCase() })}<strong>{t('prop.crud.active')}</strong>{t('prop.crud.inactive_hint_after')}</p>
            {editing !== undefined && (
                <form onSubmit={save} className="card mb-4 p-4">
                    <div className="mb-3 flex items-center justify-between">
                        <h3 className="font-semibold text-slate-800">{editing ? t('prop.crud.edit', { noun: editing.code }) : t('prop.crud.new', { noun: noun.toLowerCase() })}</h3>
                        <button type="button" className="text-slate-500 hover:text-slate-600" onClick={() => setEditing(undefined)}><X className="h-4 w-4" /></button>
                    </div>
                    <p className="mb-3 text-xs text-slate-500">{t('prop.crud.setup_hint_before', { noun })}<strong>{t('prop.crud.active')}</strong>{t('prop.crud.setup_hint_after')}</p>
                    <FormFields fields={fields} form={form} set={(k, v) => setForm((f) => ({ ...f, [k]: v }))} />
                    <div className="mt-4 flex gap-2">
                        <button className="btn-primary" disabled={busy}>{busy && <Loader2 className="h-4 w-4 animate-spin" />} {t('common.save')}</button>
                        <button type="button" className="btn-secondary" onClick={() => setEditing(undefined)}>{t('common.cancel')}</button>
                    </div>
                </form>
            )}
            <div className="card overflow-x-auto">
                <table className="table-auto w-full text-sm">
                    <thead>
                        <tr className="text-left text-xs uppercase text-slate-500">
                            {columns.map((c) => <th key={c.key} className="px-3 py-2">{c.label}</th>)}
                            <th className="px-3 py-2" />
                        </tr>
                    </thead>
                    <tbody>
                        {list.loading && !rows.length && <tr><td colSpan={columns.length + 1} className="px-3 py-6 text-center text-slate-500">{t('common.loading')}</td></tr>}
                        {!list.loading && !rows.length && <tr><td colSpan={columns.length + 1} className="px-3 py-6 text-center text-slate-500">{t('prop.crud.nothing')}</td></tr>}
                        {rows.map((r) => (
                            <tr key={r.id ?? r.code} className="border-t border-slate-100">
                                {columns.map((c) => <td key={c.key} className="whitespace-nowrap px-3 py-2">{cell(r, c)}</td>)}
                                <td className="px-3 py-2 text-right">
                                    <button className="btn-ghost btn-icon" title={t('common.edit')} onClick={() => open(r)} aria-label={t('common.edit')}><Pencil className="h-4 w-4" /></button>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </div>
    );
}
