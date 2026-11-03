<?php

namespace App\Modules\Documents\Application;

use App\Platform\Domain\DomainException;

/**
 * Report key => internal endpoint, how to turn its JSON into rows, and columns.
 * Permissions are those of the endpoint, which InternalApi calls as the user.
 */
final class ReportRegistry
{
    /** @return array<string, array{title: string, path: string, rows: callable, columns: ?array}> */
    public static function all(): array
    {
        $sections = fn (array $keys) => function (array $d) use ($keys) {
            $out = [];
            foreach ($keys as $key => $label) {
                foreach ($d[$key] ?? [] as $r) {
                    $out[] = ['section' => $label] + $r;
                }
            }

            return $out;
        };

        return [
            'trial-balance' => ['title' => 'Balance générale / Trial balance', 'path' => 'accounting/reports/trial-balance', 'rows' => fn ($d) => $d['rows'] ?? [], 'columns' => null],
            'general-ledger' => ['title' => 'Grand livre / General ledger', 'path' => 'accounting/reports/general-ledger', 'rows' => fn ($d) => $d['lines'] ?? [], 'columns' => ['date', 'number', 'journal_code', 'description', 'debit', 'credit', 'balance']],
            'income-statement' => ['title' => 'Compte de résultat / Income statement', 'path' => 'accounting/reports/income-statement', 'rows' => $sections(['revenue' => 'revenue', 'expenses' => 'expenses']), 'columns' => ['section', 'account', 'name', 'amount']],
            'balance-sheet' => ['title' => 'Bilan / Balance sheet', 'path' => 'accounting/reports/balance-sheet', 'rows' => $sections(['assets' => 'assets', 'liabilities_and_equity' => 'liabilities_and_equity']), 'columns' => ['section', 'account', 'name', 'amount']],
            'vat' => ['title' => 'TVA / VAT', 'path' => 'accounting/reports/vat', 'rows' => fn ($d) => [$d], 'columns' => null],
            'ageing' => ['title' => 'Balance âgée / Receivables ageing', 'path' => 'accounting/reports/receivables-ageing', 'rows' => function ($d) {
                $out = [];
                foreach ($d as $rows) {
                    foreach ($rows as $r) {
                        $out[] = $r;
                    }
                }

                return $out;
            }, 'columns' => ['bucket', 'number', 'bill_to_name', 'issue_date', 'due_date', 'total', 'paid', 'outstanding']],
            'managers-report' => ['title' => 'Rapport du directeur / Manager\'s report', 'path' => 'reports/dashboard', 'rows' => fn ($d) => [collect($d['today'] ?? $d)->filter(fn ($v) => is_scalar($v) || $v === null)->all()], 'columns' => null],
            'forecast' => ['title' => 'Prévisions / Forecast', 'path' => 'reports/forecast', 'rows' => fn ($d) => $d, 'columns' => ['date', 'rooms_sold', 'occupancy_percent', 'room_revenue', 'adr']],
            'police-register' => ['title' => 'Registre de police / Police register', 'path' => 'police-register', 'rows' => fn ($d) => $d, 'columns' => ['room_number', 'full_name', 'nationality', 'date_of_birth', 'place_of_birth', 'profession', 'id_type', 'id_number', 'id_expiry', 'coming_from', 'going_to', 'purpose', 'arrival', 'departure', 'checked_out_at']],
            'stock-balances' => ['title' => 'Stocks / Stock balances', 'path' => 'stock/balances', 'rows' => fn ($d) => $d, 'columns' => ['store', 'code', 'name', 'unit', 'quantity', 'avg_cost', 'value', 'reorder_level']],
            'payroll-run' => ['title' => 'Journal de paie / Payroll run', 'path' => 'payroll/runs/{run}', 'rows' => fn ($d) => collect($d['payslips'] ?? [])->map(fn ($s) => [
                'number' => $s['employee']['number'] ?? null,
                'employee' => trim(($s['employee']['first_name'] ?? '').' '.($s['employee']['last_name'] ?? '')),
                'gross' => $s['gross'], 'taxable' => $s['taxable'], 'employee_social' => $s['employee_social'],
                'taxes_withheld' => $s['taxes_withheld'], 'employer_social' => $s['employer_social'], 'other_employer' => $s['other_employer'], 'net' => $s['net'],
            ])->all(), 'columns' => null],
        ];
    }

    public static function get(string $key): array
    {
        return self::all()[$key] ?? throw new DomainException("Unknown report '$key'.", 'unknown_report');
    }

    /** Fill {placeholders} in the path from params; the rest stay as the query string. */
    public static function resolve(string $key, array $params): array
    {
        $def = self::get($key);
        $path = preg_replace_callback('/\{(\w+)\}/', function ($m) use (&$params) {
            $v = $params[$m[1]] ?? throw new DomainException("Missing parameter '{$m[1]}'.", 'missing_parameter');
            unset($params[$m[1]]);

            return rawurlencode((string) $v);
        }, $def['path']);

        return [$def, $path, $params];
    }
}
