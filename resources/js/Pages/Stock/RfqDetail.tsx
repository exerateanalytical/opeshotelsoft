import { Printer } from 'lucide-react';
import { useState } from 'react';
import Button from '@/Components/ui/Button';
import DataTable from '@/Components/ui/DataTable';
import Modal from '@/Components/ui/Modal';
import { toast } from '@/Components/ui/Toast';
import { api, useApi } from '@/lib/api';
import { useT } from '@/lib/i18n';
import { errMsg, fcfa, num } from './ControlShared';
import { esc, printHtml } from './gapcShared';

interface DLine { id: number; item_code: string; item_name: string; unit: string; quantity: number; notes: string | null }
interface DSupplier { id: number; supplier_id: number; name: string | null; sent_at: string | null }
interface Detail { id: number; number: string; title: string; status: string; due_date: string | null; notes: string | null; lines: DLine[]; suppliers: DSupplier[] }
interface Sheet { rfq: { number: string; title: string; due_date: string | null; notes: string | null }; supplier: { code: string; name: string }; lines: { item_code: string; item_name: string; unit: string; quantity: number; notes: string | null; quoted_price: number | null }[] }
export interface GridData { rfq: { number: string; title: string }; suppliers: { id: number; name: string; total: number; complete: boolean }[]; lines: { id: number; item_name: string; unit: string; quantity: number; quotes: { supplier_id: number; unit_price: number | null; line_total: number | null; lead_time_days: number | null; lowest: boolean; awarded: boolean }[] }[] }

const day = (d: string | null) => (d ? String(d).slice(0, 10) : '-');

/** Print the side-by-side supplier price comparison of an RFQ. */
export function printComparison(g: GridData, t: (k: string, p?: Record<string, string | number>) => string) {
    const head = g.suppliers.map((s) => `<th>${esc(s.name)}</th>`).join('');
    const rows = g.lines.map((l) => `<tr><td>${esc(l.item_name)}</td><td class="n">${esc(num(l.quantity))} ${esc(l.unit)}</td>${l.quotes.map((c) => `<td class="n">${c.unit_price === null ? '-' : `${esc(fcfa(c.unit_price))}${c.lowest ? ' *' : ''}${c.awarded ? ` (${esc(t('gapc.rfq.awarded'))})` : ''}${c.lead_time_days !== null ? `<br>${esc(c.lead_time_days)} ${esc(t('gapc.rfq.days'))}` : ''}`}</td>`).join('')}</tr>`).join('');
    const foot = g.suppliers.map((s) => `<td class="n"><b>${s.complete ? esc(fcfa(s.total)) : esc(t('gapc.rfq.incomplete'))}</b></td>`).join('');
    printHtml(`${g.rfq.number} ${t('gapc.rfq.comparison')}`, `<h1>${esc(g.rfq.number)} - ${esc(g.rfq.title)}</h1><p>${esc(t('gapc.rfq.comparison'))} (* = ${esc(t('gapc.rfq.lowest'))})</p><table><thead><tr><th>${esc(t('gapc.col.item'))}</th><th>${esc(t('gapc.rfq.qty'))}</th>${head}</tr></thead><tbody>${rows}<tr><td colspan="2"><b>${esc(t('gapc.rfq.total'))}</b></td>${foot}</tr></tbody></table>`);
}

/** GAPC: RFQ detail (lines, invited suppliers) with the printable supplier request sheet. */
export default function RfqDetail({ rfqId, onClose, grid }: { rfqId: number | null; onClose: () => void; grid: GridData | null }) {
    const t = useT();
    const d = useApi<Detail>(rfqId ? `stock-ops/rfqs/${rfqId}` : null, [rfqId]);
    const [busy, setBusy] = useState<number | null>(null);
    const data = d.data;

    async function printSheet(supplierId: number) {
        setBusy(supplierId);
        try {
            const s = await api<Sheet>(`stock-ops/rfqs/${rfqId}/supplier-sheet/${supplierId}`);
            const rows = s.lines.map((l) => `<tr><td>${esc(l.item_code)}</td><td>${esc(l.item_name)}</td><td class="n">${esc(num(l.quantity))} ${esc(l.unit)}</td><td>${esc(l.notes)}</td><td class="n">${l.quoted_price === null ? '&nbsp;' : esc(fcfa(l.quoted_price))}</td></tr>`).join('');
            const ok = printHtml(`${s.rfq.number} - ${s.supplier.name}`,
                `<h1>${esc(t('gapc.rfq.sheet_title'))} ${esc(s.rfq.number)}</h1><p><b>${esc(s.rfq.title)}</b></p><p>${esc(t('gapc.rfq.to'))}: ${esc(s.supplier.name)} (${esc(s.supplier.code)})</p><p>${esc(t('gapc.rfq.due'))}: ${esc(day(s.rfq.due_date))}</p>${s.rfq.notes ? `<p>${esc(s.rfq.notes)}</p>` : ''}<table><thead><tr><th>${esc(t('gapc.rfq.code'))}</th><th>${esc(t('gapc.col.item'))}</th><th>${esc(t('gapc.rfq.qty'))}</th><th>${esc(t('gapc.rfq.notes'))}</th><th>${esc(t('gapc.rfq.price'))}</th></tr></thead><tbody>${rows}</tbody></table><p class="sig">${esc(t('gapc.rfq.signature'))}: ______________________</p>`);
            if (!ok) toast.error(t('gapc.popup_blocked'));
        } catch (e) { toast.error(errMsg(e)); } finally { setBusy(null); }
    }

    return (
        <Modal open={!!rfqId} onClose={onClose} size="lg" title={data ? `${data.number} - ${data.title}` : t('gapc.rfq.detail')}
            footer={<>{grid && <Button variant="secondary" icon={Printer} onClick={() => printComparison(grid, t)}>{t('gapc.rfq.print_comparison')}</Button>}<Button onClick={onClose}>{t('gapc.close')}</Button></>}>
            {d.error && <p role="alert" className="field-error">{d.error.message}</p>}
            {data && (
                <div className="grid gap-4">
                    <dl className="grid grid-cols-2 gap-x-4 gap-y-1 text-sm">
                        <dt className="text-slate-500">{t('gapc.rfq.status')}</dt><dd>{t(`stock3.rfq.status.${data.status}`)}</dd>
                        <dt className="text-slate-500">{t('gapc.rfq.due')}</dt><dd>{day(data.due_date)}</dd>
                        {data.notes && <><dt className="text-slate-500">{t('gapc.rfq.notes')}</dt><dd>{data.notes}</dd></>}
                    </dl>
                    <DataTable<DLine> caption={t('gapc.rfq.lines')} rows={data.lines} rowKey={(l) => l.id}
                        columns={[
                            { key: 'code', header: t('gapc.rfq.code'), cell: (l) => l.item_code },
                            { key: 'name', header: t('gapc.col.item'), cell: (l) => l.item_name, hideLabel: true },
                            { key: 'qty', header: t('gapc.rfq.qty'), align: 'right', cell: (l) => `${num(l.quantity)} ${l.unit}` },
                            { key: 'notes', header: t('gapc.rfq.notes'), cell: (l) => l.notes ?? '-' },
                        ]} />
                    <DataTable<DSupplier> caption={t('gapc.rfq.suppliers')} rows={data.suppliers} rowKey={(s) => s.id}
                        columns={[
                            { key: 'name', header: t('gapc.rfq.supplier'), cell: (s) => s.name ?? `#${s.supplier_id}`, hideLabel: true },
                            { key: 'sent', header: t('gapc.rfq.sent'), cell: (s) => (s.sent_at ? day(s.sent_at) : t('gapc.rfq.not_sent')) },
                            { key: 'act', header: t('gapc.col.actions'), required: true, cell: (s) => <Button size="sm" variant="secondary" icon={Printer} loading={busy === s.supplier_id} onClick={() => printSheet(s.supplier_id)}>{t('gapc.rfq.print_sheet')}</Button> },
                        ]} />
                </div>
            )}
        </Modal>
    );
}
