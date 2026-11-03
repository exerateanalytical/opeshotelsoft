<?php

namespace App\Modules\Accounting\Http;

use App\Modules\Accounting\Application\FinancialStatements;
use App\Modules\Accounting\Application\FixedAssets;
use App\Modules\Accounting\Application\FxRevaluation;
use App\Modules\Accounting\Application\Reconciliation;
use App\Modules\Accounting\Application\YearEndClose;
use App\Modules\Accounting\Domain\AssetClass;
use App\Modules\Accounting\Domain\BankAccount;
use App\Modules\Accounting\Domain\BankStatementImport;
use App\Modules\Accounting\Domain\BankStatementLine;
use App\Modules\Accounting\Domain\DepreciationRun;
use App\Modules\Accounting\Domain\FixedAsset;
use App\Modules\Accounting\Domain\FxRevaluation as FxRun;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/** W10: reconciliation, fixed assets, cash flow, DSF export, FX revaluation, year-end close. */
class FinanceExtrasController
{
    // ---- Treasury accounts and reconciliation ----

    public function bankAccounts(Request $request)
    {
        $this->can($request, 'view');

        return BankAccount::orderBy('name')->get()->map(fn ($b) => $b->toArray() + [
            'unmatched' => BankStatementLine::where('bank_account_id', $b->id)->whereNull('journal_line_id')->count(),
        ]);
    }

    public function saveBankAccount(Request $request)
    {
        $this->can($request, 'manage');
        $data = $request->validate([
            'id' => ['nullable', 'integer'],
            'name' => ['required', 'string', 'max:120'],
            'kind' => ['required', Rule::in(['bank', 'mobile_money'])],
            'bank_name' => ['nullable', 'string', 'max:120'],
            'account_number' => ['nullable', 'string', 'max:60'],
            'iban' => ['nullable', 'string', 'max:60'],
            'currency' => ['nullable', 'string', 'size:3'],
            'gl_account' => ['required', 'string', 'regex:/^5[0-9]+$/', Rule::exists('accounts', 'code')->where('hotel_id', app(\App\Platform\Tenancy\TenantContext::class)->requireHotelId())],
            'active' => ['sometimes', 'boolean'],
        ]);
        $bank = isset($data['id']) ? BankAccount::findOrFail($data['id']) : new BankAccount;
        $bank->fill(collect($data)->except('id')->all() + ['currency' => 'XAF'])->save();

        return response()->json($bank, isset($data['id']) ? 200 : 201);
    }

    public function importStatement(Request $request, BankAccount $bank, Reconciliation $rec)
    {
        $this->can($request, 'manage');
        $data = $request->validate([
            'csv' => ['required_without:file', 'nullable', 'string'],
            'file' => ['required_without:csv', 'nullable', 'file', 'max:5120', 'mimes:csv,txt'],
            'mapping' => ['required', 'array'],
            'mapping.date' => ['required'],
            'mapping.*' => ['nullable'],
            'delimiter' => ['nullable', 'string', 'max:2'],
            'has_header' => ['nullable', 'boolean'],
            'date_format' => ['nullable', 'string', 'max:20'],
        ]);
        $csv = $request->hasFile('file') ? file_get_contents($request->file('file')->getRealPath()) : $data['csv'];
        $import = $rec->import($bank, $csv, $data['mapping'], array_filter([
            'delimiter' => $data['delimiter'] ?? null, 'has_header' => $data['has_header'] ?? null, 'date_format' => $data['date_format'] ?? null,
            'filename' => $request->hasFile('file') ? $request->file('file')->getClientOriginalName() : null,
        ], fn ($v) => $v !== null));

        return response()->json($import, 201);
    }

    public function statementLines(Request $request, BankAccount $bank)
    {
        $this->can($request, 'view');

        return BankStatementLine::where('bank_account_id', $bank->id)
            ->when($request->query('status') === 'unmatched', fn ($q) => $q->whereNull('journal_line_id'))
            ->when($request->query('status') === 'matched', fn ($q) => $q->whereNotNull('journal_line_id'))
            ->orderByDesc('date')->orderByDesc('id')->limit(500)->get();
    }

    public function imports(Request $request, BankAccount $bank)
    {
        $this->can($request, 'view');

        return BankStatementImport::where('bank_account_id', $bank->id)->latest('id')->limit(50)->get();
    }

    public function openLedgerLines(Request $request, BankAccount $bank, Reconciliation $rec)
    {
        $this->can($request, 'view');

        return $rec->openLedgerLines($bank)->orderByDesc('journal_lines.date')->limit(500)->get()
            ->map(fn ($l) => ['id' => $l->id, 'date' => $l->date, 'number' => $l->number, 'description' => $l->description ?: $l->entry_description, 'amount' => (int) $l->debit - (int) $l->credit]);
    }

    public function autoMatch(Request $request, BankAccount $bank, Reconciliation $rec)
    {
        $this->can($request, 'manage');

        return $rec->autoMatch($bank);
    }

    public function match(Request $request, int $line, Reconciliation $rec)
    {
        $this->can($request, 'manage');
        $data = $request->validate(['journal_line_id' => ['required', 'integer']]);

        return $rec->match($this->line($line), $data['journal_line_id']);
    }

    public function unmatch(Request $request, int $line, Reconciliation $rec)
    {
        $this->can($request, 'manage');

        return $rec->unmatch($this->line($line));
    }

    public function postDifference(Request $request, int $line, Reconciliation $rec)
    {
        $this->can($request, 'manage');
        $data = $request->validate(['account' => ['nullable', 'string', 'max:20'], 'description' => ['nullable', 'string', 'max:200']]);

        return response()->json($rec->postDifference($this->line($line), $data['account'] ?? null, $data['description'] ?? null), 201);
    }

    public function reconciliationReport(Request $request, BankAccount $bank, Reconciliation $rec)
    {
        $this->can($request, 'view');
        $data = $request->validate(['from' => ['required', 'date'], 'to' => ['required', 'date', 'after_or_equal:from']]);

        return $rec->report($bank, $data['from'], $data['to']);
    }

    // ---- Fixed assets ----

    public function assetClasses(Request $request)
    {
        $this->can($request, 'view');

        return AssetClass::orderBy('code')->get();
    }

    public function saveAssetClass(Request $request, FixedAssets $fa)
    {
        $this->can($request, 'manage');
        $data = $request->validate([
            'code' => ['required', 'string', 'max:20'], 'name' => ['required', 'string', 'max:120'],
            'asset_account' => ['required', 'string', $this->accountExists()], 'depreciation_account' => ['required', 'string', $this->accountExists()],
            'expense_account' => ['nullable', 'string', $this->accountExists()],
            'method' => ['required', Rule::in(['straight_line', 'declining_balance'])],
            'useful_life_months' => ['required', 'integer', 'min:1', 'max:1200'],
            'declining_coefficient' => ['nullable', 'numeric', 'min:1', 'max:5'],
        ]);
        $data['expense_account'] ??= '681000';
        $data['declining_coefficient'] ??= 2;

        return response()->json($fa->saveClass($data), 201);
    }

    public function assets(Request $request)
    {
        $this->can($request, 'view');

        return FixedAsset::orderBy('code')->get()->map(fn ($a) => $a->toArray() + ['net_book_value' => $a->netBookValue()]);
    }

    public function acquireAsset(Request $request, FixedAssets $fa)
    {
        $this->can($request, 'manage');
        $data = $request->validate([
            'asset_class_id' => ['required', 'integer'],
            'supplier_invoice_id' => ['nullable', 'integer'],
            'code' => ['nullable', 'string', 'max:20'], 'name' => ['required_without:supplier_invoice_id', 'nullable', 'string', 'max:150'],
            'acquired_on' => ['required_without:supplier_invoice_id', 'nullable', 'date'],
            'cost' => ['required_without:supplier_invoice_id', 'nullable', 'integer', 'min:1'],
            'salvage_value' => ['nullable', 'integer', 'min:0'],
            'useful_life_months' => ['nullable', 'integer', 'min:1', 'max:1200'],
            'method' => ['nullable', Rule::in(['straight_line', 'declining_balance'])],
            'declining_coefficient' => ['nullable', 'numeric', 'min:1', 'max:5'],
            'counter_account' => ['nullable', 'string', 'max:20'],
        ]);

        return response()->json($fa->acquire(array_filter($data, fn ($v) => $v !== null)), 201);
    }

    public function depreciationRuns(Request $request)
    {
        $this->can($request, 'view');

        return DepreciationRun::orderByDesc('year')->orderByDesc('month')->get();
    }

    public function runDepreciation(Request $request, FixedAssets $fa)
    {
        $this->can($request, 'manage');
        $data = $request->validate(['year' => ['required', 'integer', 'min:2000', 'max:2100'], 'month' => ['required', 'integer', 'between:1,12']]);

        return response()->json($fa->run($data['year'], $data['month']), 201);
    }

    public function disposeAsset(Request $request, FixedAsset $asset, FixedAssets $fa)
    {
        $this->can($request, 'manage');
        $data = $request->validate(['date' => ['required', 'date'], 'proceeds' => ['required', 'integer', 'min:0'], 'proceeds_account' => ['nullable', 'string', 'max:20']]);

        return $fa->dispose($asset, $data['date'], $data['proceeds'], $data['proceeds_account'] ?? null);
    }

    // ---- Reports ----

    public function cashFlow(Request $request, FinancialStatements $fs)
    {
        $this->can($request, 'view');
        $data = $request->validate(['from' => ['required', 'date'], 'to' => ['required', 'date', 'after_or_equal:from']]);

        return $fs->cashFlow($data['from'], $data['to']);
    }

    public function dsf(Request $request, FinancialStatements $fs)
    {
        $this->can($request, 'view');
        $data = $request->validate(['year' => ['required', 'integer'], 'format' => ['nullable', Rule::in(['json', 'csv'])]]);
        $dsf = $fs->dsf("{$data['year']}-01-01", "{$data['year']}-12-31");
        if (($data['format'] ?? 'json') === 'csv') {
            return response($fs->dsfCsv($dsf), 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => "attachment; filename=\"dsf-{$data['year']}.csv\""]);
        }

        return $dsf;
    }

    // ---- Foreign currency ----

    public function fxPreview(Request $request, FxRevaluation $fx)
    {
        $this->can($request, 'view');
        $data = $request->validate(['date' => ['required', 'date'], 'rates' => ['nullable', 'array'], 'rates.*' => ['numeric', 'gt:0']]);

        return $fx->preview($data['date'], $this->rates($data));
    }

    public function fxRun(Request $request, FxRevaluation $fx)
    {
        $this->can($request, 'manage');
        $data = $request->validate(['date' => ['required', 'date'], 'rates' => ['nullable', 'array'], 'rates.*' => ['numeric', 'gt:0']]);

        return response()->json($fx->run($data['date'], $this->rates($data)), 201);
    }

    public function fxRuns(Request $request)
    {
        $this->can($request, 'view');

        return FxRun::orderByDesc('date')->limit(50)->get();
    }

    // ---- Year-end ----

    public function yearStatus(Request $request, YearEndClose $ye)
    {
        $this->can($request, 'view');
        $year = (int) $request->validate(['year' => ['required', 'integer']])['year'];

        return $ye->status($year) + ['opening_balances_next_year' => $ye->openingBalances($year + 1)];
    }

    public function closeYear(Request $request, YearEndClose $ye)
    {
        abort_unless($request->user()->can('accounting.close_period'), 403);
        $year = (int) $request->validate(['year' => ['required', 'integer', 'max:'.Carbon::now()->year]])['year'];

        return response()->json($ye->close($year), 201);
    }

    private function rates(array $data): array
    {
        return collect($data['rates'] ?? [])->mapWithKeys(fn ($v, $k) => [strtoupper($k) => (float) $v])->all();
    }

    private function line(int $id): BankStatementLine
    {
        $line = BankStatementLine::findOrFail($id);
        BankAccount::findOrFail($line->bank_account_id); // tenancy: the bank account is hotel-scoped

        return $line;
    }

    private function accountExists()
    {
        return Rule::exists('accounts', 'code')->where('hotel_id', app(\App\Platform\Tenancy\TenantContext::class)->requireHotelId());
    }

    private function can(Request $request, string $ability): void
    {
        $u = $request->user();
        abort_unless($u->can('accounting.manage') || ($ability === 'view' && $u->can('accounting.view')), 403);
    }
}
