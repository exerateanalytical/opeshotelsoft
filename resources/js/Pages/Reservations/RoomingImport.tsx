import AppLayout from '@/Layouts/AppLayout';
import { Head } from '@inertiajs/react';
import { useState } from 'react';
import EmptyState from '@/Components/ui/EmptyState';
import QueryStatus from '@/Components/ui/QueryStatus';
import { activeHotelId, useApi } from '@/lib/api';
import { useT } from '@/lib/i18n';

interface Block { id: number; code: string; name: string }
interface Outcome { status: string; rows_total: number; rows_ok: number; errors: { line: number; message: string }[]; created: { number: string }[] }

function xsrf(): string {
    const m = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/);
    return m ? decodeURIComponent(m[1]) : '';
}

export default function RoomingImport() {
    const t = useT();
    const blocks = useApi<{ data: Block[] }>('blocks');
    const [block, setBlock] = useState('');
    const [file, setFile] = useState<File | null>(null);
    const [skip, setSkip] = useState(false);
    const [out, setOut] = useState<Outcome | null>(null);
    const [error, setError] = useState('');
    const [busy, setBusy] = useState(false);

    const send = async (commit: boolean) => {
        if (!block || !file) return;
        setBusy(true); setError('');
        const fd = new FormData();
        fd.append('file', file); fd.append('commit', commit ? '1' : '0'); fd.append('skip_invalid', skip ? '1' : '0');
        const hotel = activeHotelId();
        try {
            const res = await fetch(`/api/v1/experience/blocks/${block}/rooming-import`, {
                method: 'POST', credentials: 'same-origin', body: fd,
                headers: { Accept: 'application/json', 'X-XSRF-TOKEN': xsrf(), 'Idempotency-Key': crypto.randomUUID(), ...(hotel ? { 'X-Hotel-Id': hotel } : {}) },
            });
            const body = await res.json().catch(() => ({}));
            if (res.ok) setOut(body); else setError(body.detail ?? body.message ?? res.statusText);
        } catch {
            setError(t('states3.import_network'));
        }
        setBusy(false);
    };

    return (
        <AppLayout title={t('experience.rooming.title')}>
            <Head title={t('experience.rooming.title')} />
            <div className="space-y-4">
                <p className="text-sm text-slate-600">{t('experience.rooming.hint')} <a className="underline" href="/api/v1/experience/rooming/template" download>{t('experience.rooming.template')}</a></p>
                {error && <div className="rounded-md border border-red-300 bg-red-50 p-3 text-sm text-red-800">{error}</div>}
                <QueryStatus query={blocks} variant="form" rows={2} />
                {blocks.data?.data.length === 0 && <div className="card"><EmptyState title={t('states3.empty.blocks.title')} description={t('states3.empty.blocks.text')} action={<a href="/groups" className="btn-primary">{t('states3.empty.blocks.action')}</a>} /></div>}
                <div className="card grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-3">
                    <div><label className="label" htmlFor="blk">{t('experience.rooming.block')}</label>
                        <select id="blk" className="input" value={block} onChange={(e) => setBlock(e.target.value)}>
                            <option value="" />{(blocks.data?.data ?? []).map((b) => <option key={b.id} value={b.id}>{b.code} - {b.name}</option>)}
                        </select></div>
                    <div><label className="label" htmlFor="file">{t('experience.rooming.file')}</label><input id="file" type="file" accept=".csv,.xlsx" className="input" onChange={(e) => setFile(e.target.files?.[0] ?? null)} /></div>
                    <label className="min-h-11 flex items-center gap-2 self-end text-sm"><input type="checkbox" checked={skip} onChange={(e) => setSkip(e.target.checked)} />{t('experience.rooming.skip')}</label>
                    <div className="flex gap-2 sm:col-span-3">
                        <button type="button" className="btn-secondary" disabled={busy || !block || !file} onClick={() => send(false)}>{t('experience.rooming.validate')}</button>
                        <button type="button" className="btn-primary" disabled={busy || !block || !file} onClick={() => send(true)}>{t('experience.rooming.import')}</button>
                    </div>
                </div>
                {out && (
                    <div className="card space-y-2 p-4">
                        <p className="text-sm"><span className={out.status === 'imported' ? 'badge-green' : out.errors.length ? 'badge-red' : 'badge-blue'}>{t(`experience.rooming.status.${out.status}`)}</span> {t('experience.rooming.summary', { ok: out.rows_ok, total: out.rows_total })}</p>
                        {out.errors.length > 0 && (
                            <div className="table-wrap"><table className="table-auto"><thead><tr><th className="p-1">{t('experience.rooming.line')}</th><th className="p-1">{t('experience.rooming.problem')}</th></tr></thead>
                                <tbody>{out.errors.map((e, i) => <tr key={i}><td className="p-1">{e.line}</td><td className="p-1">{e.message}</td></tr>)}</tbody></table></div>
                        )}
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
