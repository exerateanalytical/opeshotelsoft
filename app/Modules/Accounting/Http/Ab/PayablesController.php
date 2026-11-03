<?php

namespace App\Modules\Accounting\Http\Ab;

use App\Modules\Accounting\Domain\BankAccount;
use App\Modules\Accounting\Domain\BankStatementLine;
use App\Modules\Accounting\Payables\BankFeed;
use App\Modules\Accounting\Payables\Dunning;
use App\Modules\Accounting\Payables\InvoiceApprovals;
use App\Modules\Accounting\Payables\InvoiceCapture;
use App\Modules\Stock\Domain\SupplierInvoice;
use Illuminate\Http\Request;

/** A-B payables endpoints under /api/v1/accounting3: approvals, capture, dunning, bank feed. Each action checks its permission. */
class PayablesController
{
    private function can(Request $r, string ...$perms): void
    {
        abort_unless(collect($perms)->contains(fn ($p) => $r->user()->can($p)), 403);
    }

    // ---- approvals ----

    public function chains(Request $r, InvoiceApprovals $a)
    {
        $this->can($r, 'payables.approval_config', 'payables.approve', 'payables.manage');

        return $a->chains();
    }

    public function saveChain(Request $r, InvoiceApprovals $a, ?int $id = null)
    {
        $this->can($r, 'payables.approval_config');
        $d = $r->validate(['name' => ['required', 'string', 'max:120'], 'min_amount' => ['required', 'integer', 'min:0'], 'max_amount' => ['nullable', 'integer', 'min:0'],
            'steps' => ['required', 'array', 'min:1', 'max:6'], 'steps.*.role' => ['required', 'string'], 'steps.*.label' => ['nullable', 'string', 'max:120'], 'active' => ['boolean']]);

        return response()->json($a->saveChain($d, $id), $id ? 200 : 201);
    }

    public function submit(Request $r, InvoiceApprovals $a, SupplierInvoice $invoice)
    {
        $this->can($r, 'payables.manage');

        return response()->json($a->submit($invoice), 201);
    }

    public function invoiceApprovals(Request $r, InvoiceApprovals $a, int $invoice)
    {
        $this->can($r, 'payables.approve', 'payables.manage');

        return $a->show($invoice);
    }

    public function inbox(Request $r, InvoiceApprovals $a)
    {
        $this->can($r, 'payables.approve');

        return $a->inbox($r->user());
    }

    public function decide(Request $r, InvoiceApprovals $a, int $id)
    {
        $this->can($r, 'payables.approve');
        $d = $r->validate(['approve' => ['required', 'boolean'], 'note' => ['nullable', 'string', 'max:1000']]);

        return $a->decide($id, (bool) $d['approve'], $d['note'] ?? null, $r->user());
    }

    public function delegations(Request $r, InvoiceApprovals $a)
    {
        $this->can($r, 'payables.approve', 'payables.approval_config');

        return $a->delegations();
    }

    public function delegate(Request $r, InvoiceApprovals $a)
    {
        $this->can($r, 'payables.approve', 'payables.approval_config');
        $d = $r->validate(['to_user_id' => ['required', 'integer', 'exists:users,id'], 'starts_on' => ['required', 'date'], 'ends_on' => ['required', 'date'],
            'reason' => ['nullable', 'string', 'max:255'], 'from_user_id' => ['nullable', 'integer', 'exists:users,id']]);
        $from = ! empty($d['from_user_id']) && $r->user()->can('payables.approval_config') ? (int) $d['from_user_id'] : null;

        return response()->json($a->delegate((int) $d['to_user_id'], $d['starts_on'], $d['ends_on'], $d['reason'] ?? null, $r->user(), $from), 201);
    }

    public function revokeDelegation(Request $r, InvoiceApprovals $a, int $id)
    {
        $this->can($r, 'payables.approve', 'payables.approval_config');

        return $a->revokeDelegation($id);
    }

    // ---- capture ----

    public function captures(Request $r, InvoiceCapture $c)
    {
        $this->can($r, 'payables.capture');

        return ['schema' => $c->schema(), 'captures' => $c->list()];
    }

    public function upload(Request $r, InvoiceCapture $c)
    {
        $this->can($r, 'payables.capture');
        $r->validate(['file' => ['required', 'file', 'max:10240', 'mimes:pdf,png,jpg,jpeg'], 'force' => ['boolean']]);

        return response()->json($c->upload($r->file('file'), $r->boolean('force')), 201);
    }

    public function capture(Request $r, InvoiceCapture $c, int $id)
    {
        $this->can($r, 'payables.capture');

        return $c->show($id);
    }

    public function captureFile(Request $r, InvoiceCapture $c, int $id)
    {
        $this->can($r, 'payables.capture');
        [$path, $name, $mime] = $c->file($id);

        return response()->download($path, $name, ['Content-Type' => $mime]);
    }

    public function convert(Request $r, InvoiceCapture $c, int $id)
    {
        $this->can($r, 'payables.capture');
        $d = $r->validate(['supplier_id' => ['required', 'integer', 'exists:suppliers,id'], 'reference' => ['required', 'string', 'max:120'], 'date' => ['required', 'date'], 'due_date' => ['nullable', 'date'],
            'net_total' => ['required', 'integer', 'min:1'], 'tax_total' => ['nullable', 'integer', 'min:0'], 'total' => ['nullable', 'integer', 'min:1'], 'goods_receipt_ids' => ['nullable', 'array'],
            'expense_lines' => ['nullable', 'array'], 'expense_lines.*.account' => ['required_with:expense_lines', 'string'], 'expense_lines.*.amount' => ['required_with:expense_lines', 'integer', 'min:1'],
            'expense_lines.*.description' => ['nullable', 'string'], 'currency' => ['nullable', 'string', 'size:3']]);

        return response()->json($c->convert($id, $d), 201);
    }

    // ---- dunning ----

    public function dunningSettings(Request $r, Dunning $d)
    {
        $this->can($r, 'accounting.dunning', 'accounting.dunning_config');

        return ['settings' => $d->settings(), 'levels' => $d->levels()];
    }

    public function saveDunningSettings(Request $r, Dunning $d)
    {
        $this->can($r, 'accounting.dunning_config');
        $x = $r->validate(['interest_enabled' => ['boolean'], 'interest_rate' => ['numeric', 'min:0', 'max:100'], 'channels' => ['array'], 'channels.*' => ['in:email,whatsapp']]);

        return $d->saveSettings($x);
    }

    public function saveDunningLevel(Request $r, Dunning $d, int $level)
    {
        $this->can($r, 'accounting.dunning_config');
        abort_unless(in_array($level, [1, 2, 3], true), 404);
        $x = $r->validate(['days_overdue' => ['integer', 'min:1', 'max:365'], 'fee' => ['integer', 'min:0'], 'active' => ['boolean'], 'subject_fr' => ['nullable', 'string', 'max:200'],
            'subject_en' => ['nullable', 'string', 'max:200'], 'body_fr' => ['nullable', 'string', 'max:3000'], 'body_en' => ['nullable', 'string', 'max:3000']]);

        return $d->saveLevel($level, $x);
    }

    public function dunningCandidates(Request $r, Dunning $d)
    {
        $this->can($r, 'accounting.dunning');

        return $d->candidates($r->query('as_of'));
    }

    public function dunningSend(Request $r, Dunning $d)
    {
        $this->can($r, 'accounting.dunning');
        $x = $r->validate(['invoice_ids' => ['nullable', 'array'], 'invoice_ids.*' => ['integer'], 'as_of' => ['nullable', 'date'], 'locale' => ['nullable', 'in:fr,en']]);

        return $d->send($x['invoice_ids'] ?? null, $x['as_of'] ?? null, $x['locale'] ?? 'fr');
    }

    public function dunningNotices(Request $r, Dunning $d)
    {
        $this->can($r, 'accounting.dunning');

        return $d->notices($r->integer('invoice_id') ?: null);
    }

    public function dunningHolds(Request $r, Dunning $d)
    {
        $this->can($r, 'accounting.dunning');

        return $d->holds();
    }

    public function dunningHold(Request $r, Dunning $d)
    {
        $this->can($r, 'accounting.dunning');
        $x = $r->validate(['company_id' => ['nullable', 'integer', 'exists:companies,id'], 'invoice_id' => ['nullable', 'integer', 'exists:invoices,id'], 'reason' => ['required', 'string', 'max:255']]);

        return response()->json($d->hold($x['company_id'] ?? null, $x['invoice_id'] ?? null, $x['reason']), 201);
    }

    public function dunningRelease(Request $r, Dunning $d, int $id)
    {
        $this->can($r, 'accounting.dunning');

        return $d->release($id);
    }

    // ---- bank feed ----

    public function feed(Request $r, BankFeed $f, BankAccount $bank)
    {
        $this->can($r, 'accounting.bank_feed');
        $x = $r->validate(['format' => ['required', 'in:csv,mt940,camt053'], 'file' => ['required', 'file', 'max:5120'], 'mapping' => ['nullable', 'array'], 'apply_rules' => ['boolean'], 'auto_match' => ['boolean']]);
        $mapping = $x['mapping'] ?? (is_string($r->input('mapping')) ? json_decode($r->input('mapping'), true) : null);

        return response()->json($f->import($bank, $x['format'], (string) file_get_contents($r->file('file')->getRealPath()), [
            'mapping' => $mapping, 'filename' => $r->file('file')->getClientOriginalName(), 'apply_rules' => $r->boolean('apply_rules', true), 'auto_match' => $r->boolean('auto_match', true),
        ]), 201);
    }

    public function rules(Request $r, BankFeed $f)
    {
        $this->can($r, 'accounting.bank_feed');

        return $f->rules();
    }

    public function saveRule(Request $r, BankFeed $f, ?int $id = null)
    {
        $this->can($r, 'accounting.bank_feed');
        $d = $r->validate(['name' => ['required', 'string', 'max:120'], 'priority' => ['integer', 'min:1', 'max:9999'], 'bank_account_id' => ['nullable', 'integer', 'exists:bank_accounts,id'],
            'direction' => ['in:in,out,any'], 'match_mode' => ['in:contains,regex'], 'pattern' => ['required', 'string', 'max:200'], 'amount_min' => ['nullable', 'integer', 'min:0'],
            'amount_max' => ['nullable', 'integer', 'min:0'], 'account_code' => ['required', 'string'], 'partner_type' => ['nullable', 'in:supplier,company,guest,employee'], 'partner_id' => ['nullable', 'integer'],
            'label' => ['nullable', 'string', 'max:200'], 'auto_post' => ['boolean'], 'active' => ['boolean']]);

        return response()->json($f->saveRule($d, $id), $id ? 200 : 201);
    }

    public function applyRules(Request $r, BankFeed $f, BankAccount $bank)
    {
        $this->can($r, 'accounting.bank_feed');

        return $f->apply($bank);
    }

    public function suggestions(Request $r, BankFeed $f, BankAccount $bank)
    {
        $this->can($r, 'accounting.bank_feed');

        return $f->suggestions($bank);
    }

    public function accept(Request $r, BankFeed $f, BankStatementLine $line)
    {
        $this->can($r, 'accounting.bank_feed');

        return $f->accept($line);
    }
}
