import AppLayout from '@/Layouts/AppLayout';
import { Head, Link, usePage } from '@inertiajs/react';
import { ArrowLeft, Loader2, Printer } from 'lucide-react';
import { useState } from 'react';
import { api, ApiError, useApi } from '@/lib/api';
import { cn, formatCurrency, formatDate } from '@/lib/utils';
import { useT } from '@/lib/i18n';
import { openPdf } from '@/Pages/Onboarding/shared';
import PrintPdfButton from '@/Components/PrintPdfButton';

interface InvoiceLine { business_date: string; description: string; quantity: number | string | null; amount: number; tax_amount: number; department?: string | null }
interface Invoice {
    id: number;
    kind?: string;
    number: string;
    folio_id: number;
    company_id: number | null;
    bill_to_name: string;
    bill_to_tax_id: string | null;
    bill_to_address: string | null;
    issue_date: string;
    due_date: string | null;
    currency: string;
    total: number;
    tax_total: number;
    paid: number;
    status: string;
    lines: InvoiceLine[];
    taxes: { code: string; name: string; amount: number }[];
}
interface Hotel { id: number; name: string; legal_name?: string | null; tax_id?: string | null; trade_register?: string | null; address?: string | null; city?: string | null; phone?: string | null; email?: string | null }
interface Code { code: string; name: string }

const UNITS = ['zéro', 'un', 'deux', 'trois', 'quatre', 'cinq', 'six', 'sept', 'huit', 'neuf', 'dix', 'onze', 'douze', 'treize', 'quatorze', 'quinze', 'seize'];
const TENS = ['', 'dix', 'vingt', 'trente', 'quarante', 'cinquante', 'soixante'];

function below100(n: number): string {
    if (n <= 16) return UNITS[n];
    if (n < 20) return 'dix-' + UNITS[n - 10];
    if (n < 70) {
        const t = Math.floor(n / 10), u = n % 10;
        return TENS[t] + (u === 0 ? '' : u === 1 ? ' et un' : '-' + UNITS[u]);
    }
    if (n < 80) return n === 71 ? 'soixante et onze' : 'soixante-' + below100(n - 60);
    if (n === 80) return 'quatre-vingts';
    return 'quatre-vingt-' + below100(n - 80);
}

function below1000(n: number): string {
    const h = Math.floor(n / 100), r = n % 100;
    const head = h === 0 ? '' : h === 1 ? 'cent' : UNITS[h] + (r === 0 ? ' cents' : ' cent');
    return [head, r ? below100(r) : ''].filter(Boolean).join(' ');
}

/** French amount in words, e.g. 1 250 000 → "un million deux cent cinquante mille". */
export function frenchWords(value: number): string {
    let n = Math.abs(Math.round(value));
    if (n === 0) return 'zéro';
    const parts: string[] = [];
    const scales: [number, string, string][] = [[1e9, 'milliard', 'milliards'], [1e6, 'million', 'millions']];
    for (const [size, one, many] of scales) {
        const q = Math.floor(n / size);
        if (q) parts.push(`${below1000(q)} ${q > 1 ? many : one}`);
        n %= size;
    }
    const th = Math.floor(n / 1000);
    if (th) parts.push(th === 1 ? 'mille' : below1000(th).replace(/cents$/, 'cent') + ' mille');
    n %= 1000;
    if (n) parts.push(below1000(n));
    return (value < 0 ? 'moins ' : '') + parts.join(' ');
}

const PRINT_CSS = `
@media print {
  @page { size: A4; margin: 14mm; }
  body * { visibility: hidden !important; }
  #invoice-print, #invoice-print * { visibility: visible !important; }
  #invoice-print { position: absolute; left: 0; top: 0; width: 100%; box-shadow: none !important; border: none !important; padding: 0 !important; }
  .no-print { display: none !important; }
}`;

export default function InvoiceShow({ id }: { id: string }) {
    const t = useT();
    const property = usePage<{ property?: { id: number; name: string } | null }>().props.property;
    const inv = useApi<Invoice>(`invoices/${id}`);
    const hotel = useApi<Hotel>(property?.id ? `hotels/${property.id}` : null);
    const methods = useApi<Code[]>('setup/payment-methods');
    const [pay, setPay] = useState({ method: '', amount: '', reference: '' });
    const [busy, setBusy] = useState(false);
    const [message, setMessage] = useState<{ ok: boolean; text: string } | null>(null);

    const i = inv.data;
    const h = hotel.data ?? (property ? { id: property.id, name: property.name } as Hotel : null);
    const due = i ? i.total - i.paid : 0;
    const net = i ? i.total - i.tax_total : 0;

    async function recordPayment(e: React.FormEvent) {
        e.preventDefault();
        if (!i) return;
        setBusy(true);
        setMessage(null);
        try {
            await api(`receivables/invoices/${i.id}/payments`, { method: 'POST', body: JSON.stringify({ ...pay, amount: Number(pay.amount), reference: pay.reference || null }) });
            setMessage({ ok: true, text: t('billing.invoice.payment_recorded') });
            setPay({ ...pay, amount: '', reference: '' });
            inv.reload();
        } catch (err) {
            setMessage({ ok: false, text: err instanceof ApiError ? err.message : String(err) });
        } finally {
            setBusy(false);
        }
    }

    return (
        <AppLayout
            title={i ? t('billing.invoice.title_n', { number: i.number }) : t('billing.invoice.title')}
            subtitle={i ? `${i.bill_to_name} · ${formatDate(i.issue_date)}` : ''}
            actions={
                <div className="flex gap-2 no-print">
                    <Link href="/billing/invoices" className="btn-secondary"><ArrowLeft className="w-4 h-4" /> {t('billing.invoice.back')}</Link>
                    {i && i.kind !== 'credit_note' && i.status !== 'cancelled' && (
                        <button className="btn-secondary" onClick={async () => {
                            const reason = window.prompt(t('documents3.credit_reason'));
                            if (!reason) return;
                            try {
                                const note = await api<{ id: number }>('documents/money/credit-notes', { method: 'POST', body: JSON.stringify({ invoice_id: i.id, reason }) });
                                window.location.href = `/billing/invoices/${note.id}`;
                            } catch (err) {
                                setMessage({ ok: false, text: err instanceof ApiError ? err.message : String(err) });
                            }
                        }}>{t('documents3.credit_note')}</button>
                    )}
                    {i?.kind === 'credit_note' && <PrintPdfButton path={`credit-note/${i.id}`} label={t('documents3.print_credit_note')} className="btn-primary inline-flex items-center gap-1" />}
                    <button className="btn-primary" onClick={() => i && openPdf(`documents/invoices/${i.id}`).catch(() => undefined)} disabled={!i || i.kind === 'credit_note'}><Printer className="w-4 h-4" /> {t('billing.invoice.print')}</button>
                </div>
            }
        >
            <Head title={i?.number ?? t('billing.invoice.title')} />
            <style>{PRINT_CSS}</style>

            {(message || inv.error) && (
                <div className={cn('no-print mb-4 rounded-lg text-sm px-4 py-3', message?.ok ? 'bg-emerald-50 text-emerald-700' : 'bg-red-50 text-red-700')}>
                    {message?.text ?? inv.error?.message}
                </div>
            )}

            {!i ? (!inv.error && <Loader2 className="w-6 h-6 mx-auto mt-16 animate-spin text-slate-500" />) : (
                <div className="grid xl:grid-cols-[1fr_320px] gap-6 items-start">
                    <div id="invoice-print" className="card bg-white text-slate-900 px-6 sm:px-10 py-8 mx-auto w-full max-w-[210mm] overflow-x-auto">
                        <div className="flex flex-col sm:flex-row sm:justify-between gap-6 border-b border-slate-200 pb-6">
                            <div>
                                <div className="text-lg font-bold">{h?.legal_name || h?.name}</div>
                                {h?.legal_name && h.legal_name !== h.name && <div className="text-sm text-slate-600">{h.name}</div>}
                                {h?.address && <div className="text-sm text-slate-600">{h.address}</div>}
                                {h?.city && <div className="text-sm text-slate-600">{h.city}</div>}
                                {(h?.phone || h?.email) && <div className="text-sm text-slate-600">{[h?.phone, h?.email].filter(Boolean).join(' · ')}</div>}
                                {h?.tax_id && <div className="text-sm text-slate-600">NIU : {h.tax_id}</div>}
                                {h?.trade_register && <div className="text-sm text-slate-600">RCCM : {h.trade_register}</div>}
                            </div>
                            <div className="sm:text-right">
                                <div className="text-2xl font-bold tracking-wide">FACTURE</div>
                                <div className="font-mono text-sm mt-1">{i.number}</div>
                                <div className="text-sm text-slate-600 mt-2">Date : {formatDate(i.issue_date)}</div>
                                {i.due_date && <div className="text-sm text-slate-600">Échéance : {formatDate(i.due_date)}</div>}
                                <span className="no-print inline-block mt-2 text-xs px-2 py-0.5 rounded bg-slate-100 text-slate-600">{t(`billing.inv_status.${i.status}`) === `billing.inv_status.${i.status}` ? i.status.replace('_', ' ') : t(`billing.inv_status.${i.status}`)}</span>
                            </div>
                        </div>

                        <div className="py-5">
                            <div className="text-xs uppercase tracking-wide text-slate-500">Facturé à</div>
                            <div className="font-semibold">{i.bill_to_name}</div>
                            {i.bill_to_address && <div className="text-sm text-slate-600 whitespace-pre-line">{i.bill_to_address}</div>}
                            {i.bill_to_tax_id && <div className="text-sm text-slate-600">NIU : {i.bill_to_tax_id}</div>}
                        </div>

                        <div className="table-wrap"><table className="table-auto">
                            <thead>
                                <tr className="border-y border-slate-300 text-left">
                                    <th className="py-2 pr-2 font-semibold">Date</th>
                                    <th className="py-2 pr-2 font-semibold">Désignation</th>
                                    <th className="py-2 pr-2 font-semibold text-right">Qté</th>
                                    <th className="py-2 pr-2 font-semibold text-right">HT</th>
                                    <th className="py-2 pr-2 font-semibold text-right">Taxes</th>
                                    <th className="py-2 font-semibold text-right">TTC</th>
                                </tr>
                            </thead>
                            <tbody>
                                {i.lines.map((l, k) => (
                                    <tr key={k} className="border-b border-slate-100">
                                        <td className="py-1.5 pr-2 whitespace-nowrap">{formatDate(l.business_date)}</td>
                                        <td className="py-1.5 pr-2">{l.description}</td>
                                        <td className="py-1.5 pr-2 text-right">{l.quantity ? Number(l.quantity) : 1}</td>
                                        <td className="py-1.5 pr-2 text-right whitespace-nowrap">{formatCurrency(l.amount - (l.tax_amount ?? 0))}</td>
                                        <td className="py-1.5 pr-2 text-right whitespace-nowrap">{formatCurrency(l.tax_amount ?? 0)}</td>
                                        <td className="py-1.5 text-right whitespace-nowrap">{formatCurrency(l.amount)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table></div>

                        <div className="flex flex-col sm:flex-row sm:justify-between gap-6 mt-6">
                            <div className="sm:w-1/2">
                                {i.taxes.length > 0 && (
                                    <div className="table-wrap"><table className="table-auto">
                                        <thead><tr className="border-b border-slate-300 text-left"><th className="py-1 font-semibold">Code</th><th className="py-1 font-semibold">Taxe</th><th className="py-1 font-semibold text-right">Montant</th></tr></thead>
                                        <tbody>
                                            {i.taxes.map((tx) => (
                                                <tr key={tx.code}><td className="py-1 font-mono text-xs">{tx.code}</td><td className="py-1">{tx.name}</td><td className="py-1 text-right">{formatCurrency(tx.amount)}</td></tr>
                                            ))}
                                        </tbody>
                                    </table></div>
                                )}
                            </div>
                            <div className="sm:w-2/5 text-sm space-y-1">
                                <div className="flex justify-between"><span>Total HT</span><span>{formatCurrency(net)}</span></div>
                                <div className="flex justify-between"><span>Total taxes</span><span>{formatCurrency(i.tax_total)}</span></div>
                                <div className="flex justify-between font-bold text-base border-t border-slate-300 pt-1"><span>Total TTC</span><span>{formatCurrency(i.total)}</span></div>
                                <div className="flex justify-between text-slate-600"><span>Déjà réglé</span><span>{formatCurrency(i.paid)}</span></div>
                                <div className="flex justify-between font-semibold"><span>Net à payer</span><span>{formatCurrency(due)}</span></div>
                            </div>
                        </div>

                        <p className="mt-6 text-sm">
                            Arrêtée la présente facture à la somme de <b>{frenchWords(i.total)} francs CFA</b> ({i.currency}).
                        </p>
                    </div>

                    {i.company_id && (
                        <div className="no-print card px-5 py-4 space-y-3">
                            <h3 className="font-semibold text-slate-800">{t('billing.invoice.record_payment')}</h3>
                            <p className="text-xs text-slate-500">{t('billing.invoice.settlement', { amount: formatCurrency(due) })}</p>
                            {due <= 0 ? <p className="text-sm text-emerald-700">{t('billing.invoice.fully_paid')}</p> : (
                                <form className="space-y-3" onSubmit={recordPayment}>
                                    <select className="input" required value={pay.method} onChange={(e) => setPay({ ...pay, method: e.target.value })} aria-label={t('billing.invoice.method')}>
                                        <option value="" disabled>{t('billing.invoice.method')}</option>
                                        {methods.data?.filter((m) => m.code !== 'CL').map((m) => <option key={m.code} value={m.code}>{m.name}</option>)}
                                    </select>
                                    <div className="flex gap-2">
                                        <input className="input" type="number" min={1} max={due} required aria-label={t('billing.folios.amount_fcfa')} placeholder={t('billing.folios.amount_fcfa')} value={pay.amount}
                                            onChange={(e) => setPay({ ...pay, amount: e.target.value })} />
                                        <button type="button" className="btn-secondary text-xs whitespace-nowrap" onClick={() => setPay({ ...pay, amount: String(due) })}>{t('billing.invoice.full')}</button>
                                    </div>
                                    <input className="input" aria-label={t('billing.invoice.reference')} placeholder={t('billing.invoice.reference')} value={pay.reference} onChange={(e) => setPay({ ...pay, reference: e.target.value })} />
                                    <button className="btn-primary w-full justify-center" disabled={busy}>{busy && <Loader2 className="w-4 h-4 animate-spin" />} {t('billing.invoice.record_payment')}</button>
                                </form>
                            )}
                        </div>
                    )}
                </div>
            )}
        </AppLayout>
    );
}
