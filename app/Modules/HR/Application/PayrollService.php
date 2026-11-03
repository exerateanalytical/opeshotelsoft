<?php

namespace App\Modules\HR\Application;

use App\Modules\HR\Application\Payroll\CameroonRulePack;
use App\Modules\HR\Application\Payroll\PayslipCalculator;
use App\Modules\HR\Domain\OvertimeEntry;
use App\Modules\HR\Domain\Employee;
use App\Modules\HR\Domain\PayrollRun;
use App\Modules\HR\Domain\RulePack;
use App\Modules\HR\Domain\SalaryAdvance;
use App\Modules\Tenancy\Domain\Hotel;
use App\Platform\Domain\DomainException;
use App\Platform\Events\EventBus;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Monthly payroll: compute (draft) → review → approve (posts GL) → pay. */
class PayrollService
{
    public function __construct(private EventBus $bus, private TenantContext $ctx) {}

    public function rulePackFor(string $country): RulePack
    {
        $pack = RulePack::where('country', $country)->where('effective_from', '<=', now())->orderByDesc('effective_from')->first();
        if (! $pack && $country === 'CM') {
            $pack = RulePack::create(['country' => 'CM', 'version' => 'CM-2026-default', 'effective_from' => '2026-01-01', 'params' => CameroonRulePack::params()]);
        }

        return $pack ?? throw new DomainException("No payroll rules are configured for country $country yet.", 'no-rule-pack');
    }

    /**
     * Compute (or recompute) a draft run.
     *
     * @param  array<int, array{bonuses?:int, service_charge?:int, overtime_hours?:float}>  $inputs  keyed by employee id
     */
    public function compute(string $period, array $inputs = []): PayrollRun
    {
        $hotel = Hotel::findOrFail($this->ctx->requireHotelId());
        $pack = $this->rulePackFor($hotel->country);
        [$year, $month] = array_map('intval', explode('-', $period));
        $start = Carbon::create($year, $month, 1);
        $end = $start->copy()->endOfMonth();

        return DB::transaction(function () use ($pack, $period, $inputs, $start, $end) {
            $run = PayrollRun::firstOrCreate(['period' => $period], ['payroll_rule_pack_id' => $pack->id, 'prepared_by' => $this->ctx->actor()?->id])->refresh();
            if ($run->status !== 'draft') {
                throw new DomainException("Payroll $period is already {$run->status}.", 'invalid-state');
            }
            $run->payslips()->delete();
            $calc = new PayslipCalculator(self::paramsOf($pack));
            $totals = array_fill_keys(['gross', 'net', 'employee_social', 'employer_social', 'taxes_withheld', 'other_employer', 'advances_recovered'], 0);

            $employees = Employee::where('hire_date', '<=', $end)
                ->where(fn ($q) => $q->whereNull('termination_date')->orWhere('termination_date', '>=', $start))->get();
            foreach ($employees as $e) {
                $in = $inputs[$e->id] ?? [];
                // Manual override, else manager-approved overtime by category (weekday/night/sunday/holiday).
                $overtime = isset($in['overtime_hours']) ? (float) $in['overtime_hours'] : OvertimeEntry::where('employee_id', $e->id)
                    ->where('status', 'approved')->whereBetween('date', [$start->toDateString(), $end->toDateString()])
                    ->get()->groupBy('category')->map(fn ($g) => round($g->sum('minutes') / 60, 2))->all();
                $advance = (int) SalaryAdvance::where('employee_id', $e->id)->where('status', 'active')->get()->sum(fn ($a) => min($a->monthly_deduction, $a->balance));
                $base = $this->proratedBase($e, $start, $end);

                $slip = $calc->compute($base, $e->allowances ?? [], $overtime, (int) ($in['bonuses'] ?? 0), (int) ($in['service_charge'] ?? 0), $advance);
                $run->payslips()->create(['employee_id' => $e->id, 'inputs' => $in + ['overtime_hours' => $overtime]] + $slip);
                foreach ($totals as $k => $_) {
                    $totals[$k] += $slip[$k];
                }
            }
            $run->update($totals + ['payroll_rule_pack_id' => $pack->id]);
            $this->bus->record('payroll.run_computed', ['id' => $run->id, 'period' => $period, 'employees' => $employees->count()] + $totals, 'payroll_run', $run->id);

            return $run->fresh('payslips.employee');
        });
    }

    /** Pack params, with Cameroon defaults filling keys added after the pack was stored. */
    public static function paramsOf(RulePack $pack): array
    {
        return $pack->country === 'CM' ? array_replace_recursive(CameroonRulePack::params(), $pack->params) : $pack->params;
    }

    public function approve(PayrollRun $run): PayrollRun
    {
        if ($run->status !== 'draft') {
            throw new DomainException("Payroll {$run->period} is {$run->status}.", 'invalid-state');
        }
        if ($run->prepared_by && $run->prepared_by === $this->ctx->actor()?->id) {
            throw new DomainException('The payroll must be approved by someone other than the preparer.', 'sod');
        }
        $pack = RulePack::findOrFail($run->payroll_rule_pack_id);
        if (! $pack->certified && app()->isProduction()) {
            throw new DomainException("Payroll rule pack {$pack->version} is not certified by an accountant.", 'rule-pack-uncertified');
        }

        return DB::transaction(function () use ($run) {
            $run->update(['status' => 'approved', 'approved_by' => $this->ctx->actor()?->id, 'approved_at' => now()]);
            foreach ($run->payslips as $slip) {
                if ($slip->advances_recovered > 0) {
                    $left = $slip->advances_recovered;
                    foreach (SalaryAdvance::where('employee_id', $slip->employee_id)->where('status', 'active')->orderBy('id')->get() as $a) {
                        $take = min($left, $a->balance, $a->monthly_deduction);
                        $a->update(['balance' => $a->balance - $take, 'status' => $a->balance - $take <= 0 ? 'repaid' : 'active']);
                        $left -= $take;
                    }
                }
            }
            $period = Carbon::createFromFormat('Y-m', $run->period)->endOfMonth()->toDateString();
            $this->bus->record('payroll.run_approved', [
                'id' => $run->id, 'period' => $run->period, 'date' => $period,
                'gross' => $run->gross, 'net' => $run->net, 'employee_social' => $run->employee_social,
                'employer_social' => $run->employer_social, 'taxes_withheld' => $run->taxes_withheld,
                'other_employer' => $run->other_employer, 'advances_recovered' => $run->advances_recovered,
                'employer_contributions' => $run->employer_social + $run->other_employer,
            ], 'payroll_run', $run->id);

            return $run;
        });
    }

    public function markPaid(PayrollRun $run, string $account): PayrollRun
    {
        if ($run->status !== 'approved') {
            throw new DomainException('Only approved payrolls can be paid.');
        }
        $run->update(['status' => 'paid', 'paid_at' => now()]);
        $this->bus->record('payroll.run_paid', [
            'id' => $run->id, 'period' => $run->period, 'amount' => $run->net, 'account' => $account,
            'date' => Hotel::findOrFail($this->ctx->requireHotelId())->business_date->toDateString(),
        ], 'payroll_run', $run->id);

        return $run;
    }

    /** Bank / mobile-money transfer file rows. */
    public function paymentFile(PayrollRun $run): array
    {
        return $run->payslips()->with('employee')->get()->map(fn ($s) => [
            'employee' => $s->employee->number, 'name' => $s->employee->full_name, 'method' => $s->employee->payment_method,
            'bank' => $s->employee->bank_name, 'account' => $s->employee->bank_account, 'momo' => $s->employee->momo_number, 'amount' => $s->net,
        ])->all();
    }

    /** CNPS and tax declaration totals for the month (DIPE-style summary). */
    public function declaration(PayrollRun $run): array
    {
        $lines = $run->payslips()->with('employee')->get();
        $sum = fn (string $code) => $lines->sum(fn ($s) => (int) (collect($s->lines)->firstWhere('code', $code)['amount'] ?? 0));

        return [
            'period' => $run->period,
            'employees' => $lines->count(),
            'gross' => $run->gross,
            'cnps' => ['employee' => $sum('CNPS'), 'employer_pension' => $sum('CNPS_PV'), 'family' => $sum('CNPS_PF'), 'accident' => $sum('CNPS_AT')],
            'taxes' => ['irpp' => $sum('IRPP'), 'cac' => $sum('CAC'), 'cfc_employee' => $sum('CFC'), 'cfc_employer' => $sum('CFC_E'), 'fne' => $sum('FNE'), 'rav' => $sum('RAV'), 'tdl' => $sum('TDL')],
            'per_employee' => $lines->map(fn ($s) => ['number' => $s->employee->number, 'name' => $s->employee->full_name, 'cnps_number' => $s->employee->cnps_number, 'tax_number' => $s->employee->tax_number, 'gross' => $s->gross, 'taxable' => $s->taxable])->all(),
        ];
    }

    private function proratedBase(Employee $e, Carbon $start, Carbon $end): int
    {
        $from = $e->hire_date->gt($start) ? $e->hire_date : $start;
        $to = $e->termination_date && $e->termination_date->lt($end) ? $e->termination_date : $end;
        $days = $from->diffInDays($to) + 1;
        $month = $start->daysInMonth;

        return $days >= $month ? $e->base_salary : (int) round($e->base_salary * $days / $month);
    }
}
