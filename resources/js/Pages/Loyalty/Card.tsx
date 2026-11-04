import AppLayout from '@/Layouts/AppLayout';
import { Head, Link } from '@inertiajs/react';
import { Ban, Printer, RefreshCw, Unlock } from 'lucide-react';
import ApiState from '@/Components/ui/ApiState';
import { EmptyFor } from '@/Components/ui/EmptyStates';
import { useState } from 'react';
import { api, useApi } from '@/lib/api';
import { formatDate } from '@/lib/utils';
import { useT } from '@/lib/i18n';
import { errorText, openPdf } from './lib';

interface CardView {
    id: number;
    card_number: string;
    kind: string;
    status: string;
    token: string;
    issued_at: string;
    expires_on: string | null;
    block_reason: string | null;
    replaced_by_card_id: number | null;
    usable: boolean;
    qr_svg: string;
    member: {
        id: number;
        number: string;
        points_balance: number;
        status: string;
        guest: { first_name: string; last_name: string } | null;
        tier: { code: string; name: string } | null;
    };
}

export default function LoyaltyCard({ cardId }: { cardId: number }) {
    const t = useT();
    const card = useApi<CardView>(`loyalty/cards/${cardId}`);
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const [reason, setReason] = useState('');

    async function act(path: 'block' | 'unblock' | 'replace') {
        setBusy(true); setError(null);
        try {
            const r = await api<{ id: number }>(`loyalty/cards/${cardId}/${path}`, { method: 'POST', body: JSON.stringify(path === 'unblock' ? {} : { reason: reason || null }) });
            if (path === 'replace') window.location.href = `/loyalty/cards/${r.id}`;
            else card.reload();
        } catch (e) { setError(errorText(e)); } finally { setBusy(false); }
    }

    const c = card.data;
    const name = c?.member.guest ? `${c.member.guest.first_name} ${c.member.guest.last_name}` : '';

    return (
        <AppLayout title={t('loyalty.cards.title')} subtitle={c ? c.member.number : ''}
            actions={c && <button className="btn-secondary inline-flex items-center gap-2" onClick={() => openPdf(`loyalty/cards/${cardId}/pdf`).catch((e) => setError(errorText(e)))}><Printer className="w-4 h-4" /> {t('loyalty.cards.print')}</button>}>
            <Head title={t('loyalty.cards.title')} />
            {error && <div className="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-2 text-sm text-red-700">{error}</div>}
            <ApiState query={card} variant="detail" empty={<EmptyFor kind="loyalty" action={<Link href="/loyalty" className="btn-secondary">{t('loyalty.title')}</Link>} />} isEmpty={(d) => !d}>{() => null}</ApiState>
            {c && (
                <div className="grid gap-6 lg:grid-cols-2">
                    <div className="card p-0 overflow-hidden max-w-md">
                        <div className="bg-slate-800 text-white px-4 py-3 flex justify-between text-sm font-semibold">
                            <span>{t('loyalty.cards.loyalty_card')}</span><span className="font-normal">{c.member.tier?.name}</span>
                        </div>
                        <div className="p-4 flex justify-between gap-4">
                            <div className="space-y-2 text-sm">
                                <div className="font-semibold text-slate-800">{name}</div>
                                <div><div className="text-xs text-slate-500">{t('loyalty.cards.member_no')}</div>{c.member.number}</div>
                                <div><div className="text-xs text-slate-500">{t('loyalty.cards.card_no')}</div><span className="font-mono">{c.card_number.replace(/(.{4})/g, '$1 ').trim()}</span></div>
                                <div><div className="text-xs text-slate-500">{t('loyalty.cards.expires')}</div>{c.expires_on ? formatDate(c.expires_on) : '—'}</div>
                            </div>
                            <div className="shrink-0 w-36 h-36 [&_svg]:w-full [&_svg]:h-full" dangerouslySetInnerHTML={{ __html: c.qr_svg }} />
                        </div>
                    </div>

                    <div className="card p-4 space-y-3 text-sm">
                        <div className="flex justify-between"><span className="text-slate-500">{t('loyalty.cards.status')}</span><span className="font-medium">{t(`loyalty.cards.status_${c.status}`)}</span></div>
                        <div className="flex justify-between"><span className="text-slate-500">{t('loyalty.cards.kind')}</span><span>{t(`loyalty.cards.kind_${c.kind}`)}</span></div>
                        <div className="flex justify-between"><span className="text-slate-500">{t('loyalty.cards.issued')}</span><span>{formatDate(c.issued_at)}</span></div>
                        <div className="flex justify-between"><span className="text-slate-500">{t('loyalty.cards.balance')}</span><span>{c.member.points_balance.toLocaleString()} pts</span></div>
                        {c.block_reason && <div className="flex justify-between"><span className="text-slate-500">{t('loyalty.cards.reason')}</span><span>{c.block_reason}</span></div>}
                        {c.replaced_by_card_id && <Link className="text-blue-700 hover:underline" href={`/loyalty/cards/${c.replaced_by_card_id}`}>{t('loyalty.cards.see_new')}</Link>}
                        {!c.replaced_by_card_id && c.status !== 'replaced' && (
                            <div className="space-y-2 pt-2 border-t">
                                <input className="input w-full" placeholder={t('loyalty.cards.reason')} aria-label={t('loyalty.cards.reason')} value={reason} onChange={(e) => setReason(e.target.value)} maxLength={255} />
                                <div className="flex flex-wrap gap-2">
                                    {c.status === 'active' && <button className="btn-secondary inline-flex items-center gap-1" disabled={busy || !reason} onClick={() => act('block')}><Ban className="w-4 h-4" /> {t('loyalty.cards.block')}</button>}
                                    {c.status === 'blocked' && <button className="btn-secondary inline-flex items-center gap-1" disabled={busy} onClick={() => act('unblock')}><Unlock className="w-4 h-4" /> {t('loyalty.cards.unblock')}</button>}
                                    <button className="btn-primary inline-flex items-center gap-1" disabled={busy} onClick={() => act('replace')}><RefreshCw className="w-4 h-4" /> {t('loyalty.cards.replace')}</button>
                                </div>
                            </div>
                        )}
                        <Link className="flex min-h-11 items-center text-blue-700 hover:underline" href={`/loyalty/members/${c.member.id}/statement`}>{t('loyalty.stmt.open')}</Link>
                    </div>
                </div>
            )}
        </AppLayout>
    );
}
