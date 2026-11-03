import { useState } from 'react';
import { router } from '@inertiajs/react';
import { useT } from '@/lib/i18n';
import MemberLayout from './MemberLayout';
import { mapi, MemberApiError } from './api';

interface Req { id: number; category: string; items: (string | { code?: string; label?: string })[]; note: string | null; status: string; room_number: string; at: string }

export default function Requests({ categories, list, home }: { categories: Record<string, string[]>; list: Req[]; home: { hotel: { name: string } | null; room: string | null } | null }) {
    const t = useT();
    const [category, setCategory] = useState('housekeeping');
    const [items, setItems] = useState<string[]>([]);
    const [note, setNote] = useState('');
    const [busy, setBusy] = useState(false);
    const [msg, setMsg] = useState<{ ok: boolean; text: string } | null>(null);

    const toggle = (i: string) => setItems((x) => (x.includes(i) ? x.filter((y) => y !== i) : [...x, i]));
    const label = (c: string) => (t(`member.req.item.${c}`) === `member.req.item.${c}` ? c.replace(/_/g, ' ') : t(`member.req.item.${c}`));

    async function send(e: React.FormEvent) {
        e.preventDefault();
        setBusy(true); setMsg(null);
        try {
            await mapi('POST', '/account/requests', { category, items, note: note || null });
            setMsg({ ok: true, text: t('member.req.sent') });
            setItems([]); setNote('');
            router.reload({ only: ['list'] });
        } catch (err) { setMsg({ ok: false, text: err instanceof MemberApiError ? err.message : String(err) }); }
        setBusy(false);
    }

    if (!home) {
        return <MemberLayout title={t('member.req.title')}><p className="card p-4">{t('member.req.not_in_house')}</p></MemberLayout>;
    }
    return (
        <MemberLayout title={t('member.req.title')}>
            <p className="text-slate-700">{t('member.req.intro', { hotel: home.hotel?.name ?? '', room: home.room ?? '' })}</p>
            <form onSubmit={send} className="card p-4 space-y-3">
                <div className="flex flex-wrap gap-2" role="group" aria-label={t('member.req.category')}>
                    {Object.keys(categories).map((c) => (
                        <button key={c} type="button" aria-pressed={category === c} onClick={() => { setCategory(c); setItems([]); }} className={category === c ? 'btn-primary' : 'btn-secondary'}>{t(`member.req.cat.${c}`)}</button>
                    ))}
                </div>
                <div className="flex flex-wrap gap-2" role="group" aria-label={t('member.req.items')}>
                    {categories[category].map((i) => (
                        <label key={i} className={`inline-flex items-center min-h-11 px-3 rounded-lg border text-sm cursor-pointer ${items.includes(i) ? 'border-navy-900 bg-navy-50' : 'border-slate-200'}`}>
                            <input type="checkbox" className="mr-2 w-5 h-5 accent-navy-800" checked={items.includes(i)} onChange={() => toggle(i)} />{label(i)}
                        </label>
                    ))}
                </div>
                <label className="block text-sm">{t('member.req.note')}<textarea className="input" rows={2} maxLength={500} value={note} onChange={(e) => setNote(e.target.value)} /></label>
                <button className="btn-primary" disabled={busy || (items.length === 0 && !note.trim())}>{t('member.req.send')}</button>
                {msg && <p role="alert" className={`text-sm ${msg.ok ? 'text-emerald-700' : 'text-red-700'}`}>{msg.text}</p>}
            </form>

            {list.length > 0 && (
                <div className="card">
                    <p className="p-4 font-semibold border-b border-slate-200">{t('member.req.history')}</p>
                    <ul className="divide-y divide-slate-100">
                        {list.map((r) => (
                            <li key={r.id} className="px-4 py-3 flex justify-between gap-3">
                                <span>
                                    <span className="block">{t(`member.req.cat.${r.category}`)}: {r.items.map((i) => (typeof i === 'string' ? label(i) : i.label ?? label(i.code ?? ''))).join(', ')}</span>
                                    {r.note && <span className="block text-sm text-slate-500">{r.note}</span>}
                                </span>
                                <span className="badge-slate self-start">{t(`member.req.status.${r.status}`)}</span>
                            </li>
                        ))}
                    </ul>
                </div>
            )}
        </MemberLayout>
    );
}
