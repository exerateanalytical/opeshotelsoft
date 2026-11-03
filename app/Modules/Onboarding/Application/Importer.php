<?php

namespace App\Modules\Onboarding\Application;

use App\Models\User;
use App\Modules\Accounting\Application\Ledger;
use App\Modules\Accounting\Domain\Account;
use App\Modules\HR\Domain\Department;
use App\Modules\Onboarding\Domain\ImportBatch;
use App\Modules\Property\Domain\RoomType;
use App\Platform\Events\EventBus;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use OpenSpout\Reader\CSV\Options as CsvOptions;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use RuntimeException;
use Throwable;

/**
 * Excel/CSV import for onboarding. Every row goes through the owning module's
 * own API endpoint (validation + permission). The whole file runs in one
 * transaction: a dry run, or any failing row, rolls everything back, so a file
 * is imported completely or not at all. The row-by-row report is kept.
 */
final class Importer
{
    public const KINDS = [
        'rooms' => ['required' => ['room_type_code', 'number'], 'optional' => ['room_type_name', 'floor', 'base_rate', 'max_adults'],
            'sample' => ['DLX', '101', 'Deluxe', '1', '50000', '2']],
        'guests' => ['required' => ['first_name', 'last_name'], 'optional' => ['title', 'email', 'phone', 'nationality', 'language', 'id_type', 'id_number', 'city', 'address'],
            'sample' => ['Jean', 'Mballa', 'M.', 'jean@example.cm', '+237677000000', 'CM', 'fr', 'national_id', '123456789', 'Douala', 'Akwa']],
        'staff' => ['required' => ['first_name', 'last_name', 'hire_date', 'base_salary'], 'optional' => ['contract_kind', 'contract_end', 'department_code', 'gender', 'phone', 'email', 'payment_method', 'momo_number', 'bank_name', 'bank_account', 'cnps_number', 'tax_number'],
            'sample' => ['Aïcha', 'Bello', '2025-01-15', '250000', 'CDI', '', 'FO', 'F', '+237699000000', 'aicha@example.cm', 'mobile_money', '699000000', '', '', '', '']],
        'opening_balances' => ['required' => ['account'], 'optional' => ['debit', 'credit', 'description'],
            'sample' => ['521', '1500000', '0', 'Banque']],
        'stock_items' => ['required' => ['code', 'name', 'category', 'unit'], 'optional' => ['purchase_unit', 'purchase_factor', 'vat_rate', 'reorder_level', 'reorder_quantity', 'inventory_account', 'cost_account'],
            'sample' => ['BEER-33', 'Bière 33cl', 'beverage', 'btl', 'case', '24', '19.25', '48', '96', '', '']],
    ];

    public function __construct(private InternalDispatcher $http, private Ledger $ledger, private EventBus $bus) {}

    /** Read a CSV (comma or semicolon) or XLSX file into rows keyed by lower-case header. */
    public function read(string $path, string $extension): array
    {
        $extension = strtolower($extension);
        if ($extension === 'xlsx') {
            $reader = new XlsxReader;
        } elseif (in_array($extension, ['csv', 'txt'], true)) {
            $first = (string) fgets(fopen($path, 'r'));
            $reader = new CsvReader(new CsvOptions(FIELD_DELIMITER: substr_count($first, ';') > substr_count($first, ',') ? ';' : ','));
        } else {
            throw new RuntimeException('Unsupported file type: use .csv or .xlsx');
        }
        $reader->open($path);
        $header = null;
        $rows = [];
        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    $cells = array_map(fn ($v) => $this->cell($v), $row->toArray());
                    if ($header === null) {
                        $header = array_map(fn ($h) => strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $h))), $cells);

                        continue;
                    }
                    if (count(array_filter($cells, fn ($v) => $v !== null && $v !== '')) === 0) {
                        continue;
                    }
                    $rows[] = array_combine($header, array_pad(array_slice($cells, 0, count($header)), count($header), null));
                }
                break; // first sheet only
            }
        } finally {
            $reader->close();
        }

        return ['header' => $header ?? [], 'rows' => $rows];
    }

    /** Run an import (dry run by default) and store the batch with its report. */
    public function run(string $kind, array $parsed, bool $dryRun, User $user, ?string $filename = null, array $options = []): ImportBatch
    {
        $spec = self::KINDS[$kind] ?? throw new RuntimeException("Unknown import kind $kind");
        $report = [];
        $missing = array_values(array_diff($spec['required'], $parsed['header']));
        if ($missing) {
            $report[] = ['row' => 1, 'ok' => false, 'errors' => ['Missing columns: '.implode(', ', $missing)]];
        } else {
            DB::beginTransaction();
            try {
                $report = $kind === 'opening_balances'
                    ? $this->openingBalances($parsed['rows'], $user, $options)
                    : $this->rows($kind, $parsed['rows']);
            } catch (Throwable $e) {
                $report[] = ['row' => 0, 'ok' => false, 'errors' => [$e->getMessage()]];
            }
            $failed = collect($report)->where('ok', false)->count();
            ($dryRun || $failed) ? DB::rollBack() : DB::commit();
        }
        $failed = collect($report)->where('ok', false)->count();

        $batch = ImportBatch::create([
            'kind' => $kind, 'filename' => $filename, 'dry_run' => $dryRun,
            'status' => $failed ? 'rejected' : ($dryRun ? 'validated' : 'imported'),
            'rows_total' => count($parsed['rows']), 'rows_ok' => collect($report)->where('ok', true)->count(), 'rows_failed' => $failed,
            'report' => $report, 'created_by' => $user->id,
        ]);
        if ($batch->status === 'imported') {
            $this->bus->record('onboarding.import.completed', ['kind' => $kind, 'rows' => $batch->rows_ok], 'import_batch', $batch->id);
        }

        return $batch;
    }

    private function rows(string $kind, array $rows): array
    {
        $report = [];
        foreach ($rows as $i => $row) {
            $line = $i + 2; // header is line 1
            try {
                DB::transaction(function () use ($kind, $row) {
                    foreach ($this->requests($kind, $row) as $req) {
                        [$status, $body] = is_callable($req) ? $req() : $this->http->send(...$req);
                        if ($status >= 400) {
                            throw new RowFailed($this->errors($status, $body));
                        }
                    }
                });
                $report[] = ['row' => $line, 'ok' => true, 'errors' => []];
            } catch (RowFailed $e) {
                $report[] = ['row' => $line, 'ok' => false, 'errors' => $e->errors];
            }
        }

        return $report;
    }

    /** API calls for one row: [method, path, body] or a closure returning [status, body]. */
    private function requests(string $kind, array $r): array
    {
        $only = fn (array $keys) => array_filter(array_intersect_key($r, array_flip($keys)), fn ($v) => $v !== null && $v !== '');

        return match ($kind) {
            'rooms' => [function () use ($r) {
                $code = strtoupper((string) $r['room_type_code']);
                $type = RoomType::where('code', $code)->first();
                if (! $type) {
                    [$s, $b] = $this->http->send('POST', 'room-types', array_filter([
                        'code' => $code, 'name' => $r['room_type_name'] ?? null ?: $code,
                        'base_rate' => isset($r['base_rate']) && $r['base_rate'] !== '' ? (int) $r['base_rate'] : null,
                        'max_adults' => isset($r['max_adults']) && $r['max_adults'] !== '' ? (int) $r['max_adults'] : null,
                    ], fn ($v) => $v !== null));
                    if ($s >= 400) {
                        return [$s, $b];
                    }
                    $typeId = $b['id'];
                } else {
                    $typeId = $type->id;
                }

                return $this->http->send('POST', 'rooms', array_filter(['room_type_id' => $typeId, 'number' => (string) $r['number'],
                    'floor' => isset($r['floor']) && $r['floor'] !== '' ? (string) $r['floor'] : null], fn ($v) => $v !== null));
            }],
            'guests' => [['POST', 'guests', $only(array_merge(self::KINDS['guests']['required'], self::KINDS['guests']['optional']))]],
            'stock_items' => [['POST', 'stock/items', $only(array_merge(self::KINDS['stock_items']['required'], self::KINDS['stock_items']['optional']))]],
            'staff' => [function () use ($r, $only) {
                $body = $only(['first_name', 'last_name', 'hire_date', 'gender', 'phone', 'email', 'payment_method', 'momo_number', 'bank_name', 'bank_account', 'cnps_number', 'tax_number']);
                if (! empty($r['department_code'])) {
                    $dept = Department::where('code', $r['department_code'])->first();
                    if (! $dept) {
                        return [422, ['errors' => ['department_code' => ["Unknown department {$r['department_code']}"]]]];
                    }
                    $body['department_id'] = $dept->id;
                }
                $body['contract'] = array_filter([
                    'kind' => $r['contract_kind'] ?? null ?: 'CDI', 'start_date' => $r['hire_date'] ?? null,
                    'end_date' => $r['contract_end'] ?? null ?: null, 'base_salary' => (int) ($r['base_salary'] ?? 0),
                ], fn ($v) => $v !== null);

                return $this->http->send('POST', 'hr/employees', $body);
            }],
        };
    }

    /** Opening GL balances: one balanced entry in the "À nouveaux" journal. */
    private function openingBalances(array $rows, User $user, array $options): array
    {
        if (! $user->can('accounting.post_manual')) {
            return [['row' => 0, 'ok' => false, 'errors' => ['Permission accounting.post_manual is required.']]];
        }
        $report = [];
        $lines = [];
        $debit = $credit = 0;
        foreach ($rows as $i => $r) {
            $errors = [];
            $code = trim((string) ($r['account'] ?? ''));
            $d = $this->amount($r['debit'] ?? 0, $errors, 'debit');
            $c = $this->amount($r['credit'] ?? 0, $errors, 'credit');
            $account = $code !== '' ? Account::where('code', $code)->first() : null;
            if (! $account) {
                $errors[] = "Unknown account $code";
            } elseif (! $account->postable) {
                $errors[] = "Account $code is not postable";
            }
            if ($d > 0 && $c > 0) {
                $errors[] = 'A line has either a debit or a credit, not both';
            }
            if (! $errors) {
                $debit += $d;
                $credit += $c;
                $lines[] = ['account' => $code, 'debit' => $d, 'credit' => $c, 'description' => $r['description'] ?? null ?: null];
            }
            $report[] = ['row' => $i + 2, 'ok' => ! $errors, 'errors' => $errors];
        }
        if (collect($report)->where('ok', false)->isEmpty()) {
            if ($debit !== $credit) {
                $report[] = ['row' => 0, 'ok' => false, 'errors' => ["Opening balances do not balance: debit $debit, credit $credit"]];
            } elseif ($debit === 0) {
                $report[] = ['row' => 0, 'ok' => false, 'errors' => ['No amounts to import']];
            } else {
                $date = $options['date'] ?? now()->toDateString();
                $this->ledger->post('AN', $date, 'Soldes d\'ouverture (import)', $lines, ['source_type' => 'onboarding_import']);
            }
        }

        return $report;
    }

    private function amount(mixed $v, array &$errors, string $field): int
    {
        if ($v === null || $v === '') {
            return 0;
        }
        $n = str_replace([' ', "\u{00A0}", "\u{202F}"], '', (string) $v);
        if (! is_numeric($n) || (float) $n < 0 || floor((float) $n) != (float) $n) {
            $errors[] = "Invalid $field amount: $v";

            return 0;
        }

        return (int) $n;
    }

    private function errors(int $status, mixed $body): array
    {
        if (is_array($body) && ! empty($body['errors']) && is_array($body['errors'])) {
            return collect($body['errors'])->flatMap(fn ($msgs, $field) => array_map(fn ($m) => "$field: $m", (array) $msgs))->values()->all();
        }

        return [($body['detail'] ?? $body['message'] ?? $body['title'] ?? null) ?: "HTTP $status"];
    }

    private function cell(mixed $v): string|int|float|null
    {
        return match (true) {
            $v instanceof DateTimeInterface => $v->format('Y-m-d'),
            is_float($v) && floor($v) == $v => (int) $v,
            is_string($v) => trim($v),
            default => $v,
        };
    }
}
