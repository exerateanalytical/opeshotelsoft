import { useState } from 'react';
import Modal from '@/Components/ui/Modal';
import EmptyState from '@/Components/ui/EmptyState';
import { toast } from '@/Components/ui/Toast';
import { api, useApi } from '@/lib/api';
import { useT } from '@/lib/i18n';
import { useCan } from '@/lib/useCan';
import { formatDate } from '@/lib/utils';
import { errMsg } from './shared';
import { C, CardTable, SectionTitle, yesNo } from './gapbShared';

interface Hold { id: number; company_id: number | null; invoice_id: number | null; reason: string; active: boolean; created_at: string; released_at: string | null }

/** Dunning holds with a release action (POST accounting3/dunning/holds/{id}/release). */
export default function DunningHoldsPanel({ version, onChange }: { version: number; onChange: () => void }) {
    const t = useT();
    const can = useCan();
    const holds = useApi<Hold[]>('accounting3/dunning/holds', [version]);
    const [confirm, setConfirm] = useState<Hold | null>(null);
    const [busy, setBusy] = useState(false);
    const [showAll, setShowAll] = useState(false);
    const rows = (holds.data ?? []).filter((h) => showAll || yesNo(h.active));
    const target = (h: Hold) => (h.invoice_id ? t('gapb.hold.invoice', { id: h.invoice_id }) : t('gapb.hold.company', { id: h.company_id ?? '-' }));

    const release = async () => {
        if (!confirm) return;
        setBusy(true);
        try {
            await api(`accounting3/dunning/holds/${confirm.id}/release`, { method: 'POST' });
            toast.success(t('gapb.hold.released'));
            setConfirm(null);
            holds.reload();
            onChange();
        } catch (e) { toast.error(errMsg(e)); } finally { setBusy(false); }
    };

    return (
        <div className="card mt-4 p-4">
            <div className="flex flex-wrap items-start justify-between gap-2">
                <SectionTitle hint={t('gapb.hold.hint')}>{t('gapb.hold.title')}</SectionTitle>
                <label className="flex min-h-11 items-center gap-2 text-sm"><input type="checkbox" checked={showAll} onChange={(e) => setShowAll(e.target.checked)} />{t('gapb.hold.show_released')}</label>
            </div>
            {holds.error && <p className="text-sm text-red-700" role="alert">{holds.error.message}</p>}
            {holds.data && rows.length === 0 && <EmptyState title={t('gapb.hold.empty')} />}
            {rows.length > 0 && (
                <CardTable caption={t('gapb.hold.title')} head={[t('gapb.hold.target'), t('gapb.reason'), t('gapb.since'), t('acc3.status'), '']}>
                    {rows.map((h) => (
                        <tr key={h.id}>
                            <C label={t('gapb.hold.target')} b>{target(h)}</C>
                            <C label={t('gapb.reason')}>{h.reason}</C>
                            <C label={t('gapb.since')}>{formatDate(h.created_at)}</C>
                            <C label={t('acc3.status')}>{yesNo(h.active) ? <span className="badge-yellow">{t('gapb.hold.active')}</span> : <span className="badge-slate">{t('gapb.hold.released_on', { date: h.released_at ? formatDate(h.released_at) : '-' })}</span>}</C>
                            <C label="" r>{yesNo(h.active) && can('accounting.dunning') && <button type="button" className="btn-secondary btn-sm" onClick={() => setConfirm(h)}>{t('gapb.hold.release')}</button>}</C>
                        </tr>
                    ))}
                </CardTable>
            )}
            <Modal open={!!confirm} onClose={() => setConfirm(null)} title={t('gapb.hold.release_title')}
                footer={<><button type="button" className="btn-secondary" onClick={() => setConfirm(null)}>{t('gapb.cancel')}</button><button type="button" className="btn-primary" disabled={busy} onClick={release}>{t('gapb.hold.release')}</button></>}>
                {confirm && <p className="text-sm">{t('gapb.hold.release_body', { target: target(confirm) })}</p>}
            </Modal>
        </div>
    );
}
