import { useEffect, useState } from 'react';
import { api, ApiError, useApi } from '@/lib/api';
import { useT } from '@/lib/i18n';
import QueryStatus from '@/Components/ui/QueryStatus';
import EmptyState from '@/Components/ui/EmptyState';
import { Image as ImageIcon } from 'lucide-react';
import { upload } from './shared';

interface WM { mode: 'none' | 'text' | 'image'; text: string; opacity: number; position: 'center' | 'diagonal' | 'tile'; apply: 'all' | 'drafts'; image: string | null }

/** Platform watermark form, shared by the onboarding wizard step and the Branding screen. */
export default function WatermarkForm({ onSaved, nextLabel, onNext }: { onSaved?: () => void; nextLabel?: string; onNext?: () => void }) {
    const t = useT();
    const wm = useApi<WM>('documents/watermark');
    const [f, setF] = useState<WM | null>(null);
    const [msg, setMsg] = useState<{ ok: boolean; text: string } | null>(null);
    const [busy, setBusy] = useState(false);

    useEffect(() => {
        if (wm.data) setF(wm.data);
    }, [wm.data]);
    if (!f) return <QueryStatus query={wm} variant="form" rows={2} />;
    const set = <K extends keyof WM>(k: K, v: WM[K]) => setF({ ...f, [k]: v });

    async function run(fn: () => Promise<unknown>) {
        setBusy(true);
        setMsg(null);
        try {
            await fn();
            setMsg({ ok: true, text: t('onboarding.saved') });
            wm.reload();
            onSaved?.();
        } catch (e) {
            const err = e instanceof ApiError ? e : null;
            setMsg({ ok: false, text: (err?.problem.errors ? Object.values(err.problem.errors).flat().join(' ') : err?.message) || t('onboarding.error') });
        } finally {
            setBusy(false);
        }
    }

    async function image(file: File) {
        const form = new FormData();
        form.append('image', file);
        await run(async () => {
            const r = await upload<{ message?: string }>('documents/watermark/image', form);
            if (!r.ok) throw new Error(r.body?.message ?? t('onboarding.error'));
        });
    }

    const save = () => run(() => api('documents/watermark', {
        method: 'PUT',
        body: JSON.stringify({ mode: f.mode, text: f.text, opacity: Number(f.opacity), position: f.position, apply: f.apply }),
    }));

    return (
        <div className="space-y-3 max-w-xl">
            <p className="text-sm text-slate-500">{t('documents2.wm.hint')}</p>
            {msg && <p className={`text-sm ${msg.ok ? 'text-emerald-700' : 'text-red-700'}`}>{msg.text}</p>}
            <div className="grid gap-3 sm:grid-cols-2">
                <label className="block">
                    <span className="label">{t('documents2.wm.mode')}</span>
                    <select className="select" value={f.mode} onChange={(e) => set('mode', e.target.value as WM['mode'])}>
                        <option value="none">{t('documents2.wm.none')}</option>
                        <option value="text">{t('documents2.wm.text')}</option>
                        <option value="image">{t('documents2.wm.image')}</option>
                    </select>
                </label>
                <label className="block">
                    <span className="label">{t('documents2.wm.opacity')}</span>
                    <input className="input" type="number" min={2} max={60} value={f.opacity} onChange={(e) => set('opacity', Number(e.target.value))} />
                </label>
                <label className="block">
                    <span className="label">{t('documents2.wm.position')}</span>
                    <select className="select" value={f.position} onChange={(e) => set('position', e.target.value as WM['position'])}>
                        <option value="center">{t('documents2.wm.center')}</option>
                        <option value="diagonal">{t('documents2.wm.diagonal')}</option>
                        <option value="tile">{t('documents2.wm.tile')}</option>
                    </select>
                </label>
                <label className="block">
                    <span className="label">{t('documents2.wm.apply')}</span>
                    <select className="select" value={f.apply} onChange={(e) => set('apply', e.target.value as WM['apply'])}>
                        <option value="all">{t('documents2.wm.apply_all')}</option>
                        <option value="drafts">{t('documents2.wm.apply_drafts')}</option>
                    </select>
                </label>
            </div>
            {f.mode === 'text' && (
                <label className="block">
                    <span className="label">{t('documents2.wm.text_label')}</span>
                    <input className="input" maxLength={60} value={f.text} onChange={(e) => set('text', e.target.value)} />
                </label>
            )}
            <div className="space-y-2">
                <span className="label">{t('documents2.wm.upload')}</span>
                {f.image ? <img src={f.image} alt="" className="max-h-16 border border-slate-200" /> : <div className="border border-dashed border-slate-300 [&>div]:py-4"><EmptyState icon={ImageIcon} title={t('documents2.wm.upload')} /></div>}
                <input type="file" className="block min-h-11 w-full min-w-0 text-sm" aria-label={t('documents2.wm.upload')} accept="image/png,image/jpeg" onChange={(e) => e.target.files?.[0] && image(e.target.files[0])} />
                {f.image && <button type="button" className="btn-secondary" disabled={busy} onClick={() => run(() => api('documents/watermark/image/remove', { method: 'POST' }))}>{t('documents2.wm.remove')}</button>}
            </div>
            <div className="flex gap-2">
                <button type="button" className="btn-primary" disabled={busy} onClick={save}>{t('documents2.wm.save')}</button>
                {onNext && <button type="button" className="btn-secondary" disabled={busy} onClick={async () => { await save(); onNext(); }}>{nextLabel ?? t('onboarding.save_continue')}</button>}
            </div>
        </div>
    );
}
