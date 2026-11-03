<?php

namespace App\Modules\Accounting\Application\Core;

use App\Platform\Domain\DomainException;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Analytic dimensions on journal lines: department (existing), outlet, project, cost_centre.
 * A line that names a department but no outlet/project/cost centre receives the hotel's configured defaults
 * for that department, so automatic postings (POS, folio, stock, payroll) are analytic without changes at the source.
 */
class DimensionDefaults
{
    public const KINDS = ['department', 'outlet', 'project', 'cost_centre'];

    private array $cache = [];

    public function __construct(private TenantContext $ctx) {}

    /** @param array $line normalized ledger line */
    public function fill(array $line): array
    {
        $dept = $line['department'] ?? null;
        if (! $dept) {
            return $line;
        }
        $hotelId = $this->ctx->hotelId();
        $key = $hotelId.'|'.$dept;
        $this->cache[$key] ??= (array) (DB::table('dimension_defaults')->where(['hotel_id' => $hotelId, 'department' => $dept])->first() ?? []);
        foreach (['outlet', 'project', 'cost_centre'] as $k) {
            if (empty($line[$k]) && ! empty($this->cache[$key][$k])) {
                $line[$k] = $this->cache[$key][$k];
            }
        }

        return $line;
    }

    public function forget(): void
    {
        $this->cache = [];
    }

    public function list(): array
    {
        return DB::table('analytic_dimensions')->where('hotel_id', $this->ctx->requireHotelId())->orderBy('kind')->orderBy('code')->get()->all();
    }

    public function save(string $kind, string $code, string $name, bool $active = true): array
    {
        if (! in_array($kind, self::KINDS, true)) {
            throw new DomainException('Unknown dimension kind.', 'invalid-dimension');
        }
        $h = $this->ctx->requireHotelId();
        $where = ['hotel_id' => $h, 'kind' => $kind, 'code' => $code];
        $exists = DB::table('analytic_dimensions')->where($where)->exists();
        DB::table('analytic_dimensions')->updateOrInsert($where, ['group_id' => $this->ctx->groupId(), 'name' => $name, 'active' => $active, 'updated_at' => now()] + ($exists ? [] : ['created_at' => now()]));
        app(AuditTrail::class)->record('dimension_saved', 'dimension', "$kind:$code", ['name' => $name, 'active' => $active]);

        return (array) DB::table('analytic_dimensions')->where($where)->first();
    }

    public function defaults(): array
    {
        return DB::table('dimension_defaults')->where('hotel_id', $this->ctx->requireHotelId())->orderBy('department')->get()->all();
    }

    public function saveDefault(string $department, ?string $outlet, ?string $project, ?string $costCentre): array
    {
        $h = $this->ctx->requireHotelId();
        $where = ['hotel_id' => $h, 'department' => $department];
        $exists = DB::table('dimension_defaults')->where($where)->exists();
        DB::table('dimension_defaults')->updateOrInsert($where, ['group_id' => $this->ctx->groupId(), 'outlet' => $outlet, 'project' => $project, 'cost_centre' => $costCentre, 'updated_at' => now()] + ($exists ? [] : ['created_at' => now()]));
        $this->forget();
        app(AuditTrail::class)->record('dimension_default_saved', 'dimension', "default:$department", compact('outlet', 'project', 'costCentre'));

        return (array) DB::table('dimension_defaults')->where($where)->first();
    }

    /**
     * Income and expense by dimension value for a period.
     * Revenue = credit - debit on class 7; expenses = debit - credit on class 6.
     */
    public function report(string $by, string $from, string $to): array
    {
        if (! in_array($by, self::KINDS, true)) {
            throw new DomainException('Unknown dimension kind.', 'invalid-dimension');
        }
        $rows = DB::table('journal_lines')->where('hotel_id', $this->ctx->requireHotelId())->whereBetween('date', [$from, $to])
            ->whereRaw("substr(account_code,1,1) in ('6','7')")
            ->selectRaw("coalesce($by, '') as dim, account_code, sum(debit) as debit, sum(credit) as credit")
            ->groupByRaw("coalesce($by, ''), account_code")->get();
        $names = DB::table('analytic_dimensions')->where(['hotel_id' => $this->ctx->requireHotelId(), 'kind' => $by])->pluck('name', 'code');
        $out = [];
        foreach ($rows as $r) {
            $out[$r->dim] ??= ['code' => $r->dim, 'name' => $r->dim === '' ? null : ($names[$r->dim] ?? $r->dim), 'revenue' => 0, 'expenses' => 0, 'result' => 0, 'accounts' => []];
            $is7 = $r->account_code[0] === '7';
            $amt = $is7 ? (int) $r->credit - (int) $r->debit : (int) $r->debit - (int) $r->credit;
            $out[$r->dim][$is7 ? 'revenue' : 'expenses'] += $amt;
            $out[$r->dim]['accounts'][] = ['account' => $r->account_code, 'amount' => $amt, 'type' => $is7 ? 'revenue' : 'expense'];
        }
        foreach ($out as &$o) {
            $o['result'] = $o['revenue'] - $o['expenses'];
        }
        unset($o);
        ksort($out);

        return ['by' => $by, 'from' => $from, 'to' => $to, 'rows' => array_values($out),
            'total' => ['revenue' => array_sum(array_column($out, 'revenue')), 'expenses' => array_sum(array_column($out, 'expenses'))]];
    }
}
