<?php

namespace App\Modules\Accounting\Http;

use App\Modules\FrontOffice\Application\FolioService;
use App\Modules\FrontOffice\Domain\Folio;
use App\Modules\FrontOffice\Domain\Invoice;
use App\Modules\Guests\Domain\Company;
use App\Platform\Domain\DomainException;
use App\Platform\Events\EventBus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * City-ledger receivables: company statements and payments against invoices.
 * Payments are posted on the company's city-ledger folio, so the folio, the
 * invoice and the general ledger (via folio.line_posted) always agree.
 */
class ReceivablesController
{
    public function statement(Request $request, Company $company)
    {
        abort_unless($request->user()->can('receivables.view') || $request->user()->can('receivables.manage'), 403);

        return [
            'company' => $company->only(['id', 'name', 'tax_id', 'credit_limit', 'payment_terms_days']),
            'invoices' => Invoice::where('company_id', $company->id)->orderBy('issue_date')->get(['id', 'number', 'issue_date', 'due_date', 'total', 'paid', 'status']),
            'balance' => (int) Folio::where('company_id', $company->id)->where('kind', 'city_ledger')->sum('balance'),
        ];
    }

    /** Company pays one invoice (bank transfer, cheque, mobile money). */
    public function pay(Request $request, Invoice $invoice, FolioService $folios, EventBus $bus)
    {
        abort_unless($request->user()->can('receivables.manage'), 403);
        $data = $request->validate(['amount' => ['required', 'integer', 'min:1'], 'method' => ['required', 'string'], 'reference' => ['nullable', 'string']]);
        if (! $invoice->company_id) {
            throw new DomainException('Only company (city-ledger) invoices are settled here.');
        }
        if ($invoice->paid + $data['amount'] > $invoice->total) {
            throw new DomainException('Payment exceeds the invoice balance.');
        }

        return DB::transaction(function () use ($invoice, $data, $folios, $bus) {
            $ledger = Folio::where('company_id', $invoice->company_id)->where('kind', 'city_ledger')->where('status', 'open')->firstOrFail();
            $folios->payment($ledger, $data['method'], $data['amount'], $data['reference'] ?? $invoice->number, ['description' => "Règlement facture {$invoice->number}"]);
            $paid = $invoice->paid + $data['amount'];
            $invoice->update(['paid' => $paid, 'status' => $paid >= $invoice->total ? 'paid' : 'partially_paid']);
            $bus->record('receivables.payment_received', ['invoice_id' => $invoice->id, 'company_id' => $invoice->company_id, 'amount' => $data['amount']], 'invoice', $invoice->id);

            return $invoice;
        });
    }
}
