<?php

namespace App\Modules\Accounting\Http\Ab;

use App\Modules\Accounting\Support\Sheets;
use App\Modules\Accounting\Tax\CountryProfiles;
use App\Modules\Accounting\Tax\PayrollFilings;
use App\Modules\Accounting\Tax\StampDuty;
use App\Modules\Accounting\Tax\VatReturns;
use App\Modules\Accounting\Tax\Withholding;
use Illuminate\Http\Request;

/** A-B tax endpoints under /api/v1/accounting3/tax. */
class TaxController
{
    private function can(Request $r, string ...$perms): void
    {
        abort_unless(collect($perms)->contains(fn ($p) => $r->user()->can($p)), 403);
    }

    public function profile(Request $r, CountryProfiles $p)
    {
        $this->can($r, 'accounting.tax', 'accounting.tax_file');

        return ['profile' => $p->profile(), 'countries' => $p->countries()];
    }

    public function saveProfile(Request $r, CountryProfiles $p)
    {
        $this->can($r, 'accounting.tax_file');
        $d = $r->validate(['country' => ['required', 'in:CM,GA,CG,TD,CF,GQ'], 'config' => ['nullable', 'array'], 'config.vat_rate' => ['numeric', 'min:0', 'max:50'], 'config.vat_form' => ['string', 'max:200'],
            'config.filing_day' => ['integer', 'min:1', 'max:28']]);

        return $p->save($d['country'], $d['config'] ?? []);
    }

    // ---- VAT ----

    public function vatReturns(Request $r, VatReturns $v)
    {
        $this->can($r, 'accounting.tax', 'accounting.tax_file');

        return $v->history();
    }

    public function vatPreview(Request $r, VatReturns $v)
    {
        $this->can($r, 'accounting.tax', 'accounting.tax_file');
        $d = $r->validate(['from' => ['required', 'date'], 'to' => ['required', 'date']]);

        return $v->compute($d['from'], $d['to']);
    }

    public function vatPrepare(Request $r, VatReturns $v)
    {
        $this->can($r, 'accounting.tax', 'accounting.tax_file');
        $d = $r->validate(['from' => ['required', 'date'], 'to' => ['required', 'date']]);

        return response()->json($v->prepare($d['from'], $d['to']), 201);
    }

    public function vatShow(Request $r, VatReturns $v, int $id)
    {
        $this->can($r, 'accounting.tax', 'accounting.tax_file');

        return $v->show($id);
    }

    public function vatFile(Request $r, VatReturns $v, int $id)
    {
        $this->can($r, 'accounting.tax_file');
        $d = $r->validate(['reference' => ['required', 'string', 'max:120'], 'filed_on' => ['nullable', 'date']]);

        return $v->file($id, $d['reference'], $d['filed_on'] ?? null);
    }

    public function vatAmend(Request $r, VatReturns $v, int $id)
    {
        $this->can($r, 'accounting.tax_file');

        return response()->json($v->amend($id), 201);
    }

    public function vatVerify(Request $r, VatReturns $v, int $id)
    {
        $this->can($r, 'accounting.tax', 'accounting.tax_file');

        return $v->verify($id);
    }

    public function vatExport(Request $r, VatReturns $v, int $id)
    {
        $this->can($r, 'accounting.tax', 'accounting.tax_file');
        $f = $r->query('format', 'xlsx') === 'csv' ? 'csv' : 'xlsx';
        [$content, $name] = $v->export($id, $f);

        return Sheets::download($content, $name, $f);
    }

    // ---- withholding ----

    public function whtRates(Request $r, Withholding $w)
    {
        $this->can($r, 'accounting.tax', 'accounting.tax_file');

        return $w->rates();
    }

    public function saveWhtRate(Request $r, Withholding $w)
    {
        $this->can($r, 'accounting.tax_file');
        $d = $r->validate(['code' => ['required', 'string', 'max:30'], 'name' => ['required', 'string', 'max:150'], 'rate' => ['required', 'numeric', 'min:0', 'max:50'],
            'base' => ['in:net,gross'], 'threshold' => ['integer', 'min:0'], 'active' => ['boolean']]);

        return $w->saveRate($d);
    }

    public function supplierProfile(Request $r, Withholding $w, int $supplier)
    {
        $this->can($r, 'accounting.tax_file', 'payables.manage');
        $d = $r->validate(['withholding_code' => ['nullable', 'string', 'max:30'], 'exempt' => ['boolean'], 'niu' => ['nullable', 'string', 'max:40']]);

        return $w->saveSupplierProfile($supplier, $d);
    }

    public function certificates(Request $r, Withholding $w)
    {
        $this->can($r, 'accounting.tax', 'accounting.tax_file', 'payables.manage');

        return $w->certificates($r->query('status'));
    }

    public function certificate(Request $r, Withholding $w, int $id)
    {
        $this->can($r, 'accounting.tax', 'accounting.tax_file', 'payables.manage');

        return $w->certificate($id);
    }

    public function remit(Request $r, Withholding $w)
    {
        $this->can($r, 'accounting.tax_file');
        $d = $r->validate(['ids' => ['required', 'array', 'min:1'], 'ids.*' => ['integer'], 'date' => ['required', 'date'], 'account' => ['required', 'string'], 'reference' => ['nullable', 'string', 'max:120']]);

        return $w->remit($d['ids'], $d['date'], $d['account'], $d['reference'] ?? null);
    }

    // ---- stamp duty ----

    public function stampRules(Request $r, StampDuty $s)
    {
        $this->can($r, 'accounting.tax', 'accounting.tax_file');

        return ['rules' => $s->rules(), 'register' => $s->register($r->query('from'), $r->query('to'))];
    }

    public function saveStampRule(Request $r, StampDuty $s)
    {
        $this->can($r, 'accounting.tax_file');
        $d = $r->validate(['code' => ['required', 'string', 'max:30'], 'name' => ['required', 'string', 'max:150'], 'applies_to' => ['required', 'in:cash_receipt,supplier_payment,contract,other'],
            'kind' => ['required', 'in:percent,fixed'], 'rate' => ['numeric', 'min:0', 'max:100'], 'fixed_amount' => ['integer', 'min:0'], 'min_base' => ['integer', 'min:0'], 'min_duty' => ['integer', 'min:0'], 'active' => ['boolean']]);

        return $s->saveRule($d);
    }

    public function stampRecord(Request $r, StampDuty $s)
    {
        $this->can($r, 'accounting.tax_file');
        $d = $r->validate(['code' => ['required', 'string'], 'base' => ['required', 'integer', 'min:0'], 'date' => ['required', 'date'], 'source_type' => ['nullable', 'string', 'max:30'], 'source_id' => ['nullable', 'string', 'max:40'],
            'preview' => ['boolean']]);

        return $r->boolean('preview') ? $s->compute($d['code'], $d['base']) : response()->json($s->record($d['code'], $d['base'], $d['date'], $d['source_type'] ?? null, $d['source_id'] ?? null), 201);
    }

    // ---- payroll filings ----

    public function payrollFilings(Request $r, PayrollFilings $p)
    {
        $this->can($r, 'payroll.filings');

        return $p->history();
    }

    public function payrollPreview(Request $r, PayrollFilings $p, string $kind)
    {
        $this->can($r, 'payroll.filings');
        $d = $r->validate(['period' => ['required', 'regex:/^\d{4}-\d{2}$/']]);

        return $p->build($kind, $d['period']);
    }

    public function payrollExport(Request $r, PayrollFilings $p, string $kind)
    {
        $this->can($r, 'payroll.filings');
        $d = $r->validate(['period' => ['required', 'regex:/^\d{4}-\d{2}$/'], 'format' => ['nullable', 'in:xlsx,csv']]);
        $f = $d['format'] ?? 'xlsx';
        [$content, $name] = $p->export($kind, $d['period'], $f);

        return Sheets::download($content, $name, $f);
    }

    public function payrollFiled(Request $r, PayrollFilings $p, int $id)
    {
        $this->can($r, 'payroll.filings', 'accounting.tax_file');
        $d = $r->validate(['reference' => ['required', 'string', 'max:120'], 'filed_on' => ['nullable', 'date']]);

        return $p->markFiled($id, $d['reference'], $d['filed_on'] ?? null);
    }
}
