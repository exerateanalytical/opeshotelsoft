<?php

namespace App\Modules\HR\Application\Lifecycle;

use App\Modules\HR\Application\HrService;
use App\Modules\HR\Domain\Department;
use App\Modules\HR\Domain\Employee;
use App\Modules\HR\Domain\LeaveRequest;
use App\Modules\HR\Domain\Lifecycle\CompensationChange;
use App\Modules\HR\Domain\Lifecycle\PayElement;
use App\Modules\HR\Domain\Lifecycle\SalaryHistory;
use App\Modules\HR\Domain\Lifecycle\ServiceChargePool;
use App\Modules\HR\Domain\Lifecycle\Transfer;
use App\Modules\HR\Domain\Position;
use App\Modules\Tenancy\Domain\Hotel;
use App\Platform\Domain\DomainException;
use App\Platform\Domain\NumberSequence;
use App\Platform\Events\EventBus;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/** Raises & promotions, bonuses & allowances, service-charge pools, transfers between hotels. */
class LifecycleService
{
    public function __construct(private TenantContext $ctx, private EventBus $bus, private EContractService $contracts, private HrService $hr) {}

    // ---- Raises & promotions -------------------------------------------------

    public function requestChange(Employee $e, array $data): CompensationChange
    {
        if ($e->status === 'terminated') {
            throw new DomainException('This employee has left.', 'invalid-state');
        }
        $to = (int) ($data['to_salary'] ?? $e->base_salary);
        $pos = $data['to_position_id'] ?? null;
        if ($pos) {
            Position::findOrFail($pos);
        }
        if ($data['type'] === 'promotion' && ! $pos) {
            throw new DomainException('A promotion needs the new position.');
        }
        if ($data['type'] === 'raise' && $to <= $e->base_salary) {
            throw new DomainException('A raise must increase the base salary.');
        }
        if (CompensationChange::where('employee_id', $e->id)->whereIn('status', ['pending', 'approved'])->exists()) {
            throw new DomainException('This employee already has a pending pay change.', 'duplicate');
        }
        $c = CompensationChange::create([
            'employee_id' => $e->id, 'type' => $data['type'], 'from_salary' => $e->base_salary, 'to_salary' => $to,
            'from_position_id' => $e->position_id, 'to_position_id' => $pos ?? $e->position_id,
            'effective_date' => $data['effective_date'], 'reason' => $data['reason'] ?? null, 'requested_by' => $this->ctx->actor()?->id,
        ]);
        $this->bus->record('hr.pay_change_requested', ['id' => $c->id, 'employee_id' => $e->id, 'type' => $c->type, 'to_salary' => $to], 'compensation_change', $c->id);

        return $c;
    }

    public function decideChange(CompensationChange $c, bool $approve): CompensationChange
    {
        if ($c->status !== 'pending') {
            throw new DomainException("This request is {$c->status}.", 'invalid-state');
        }
        $this->assertSod($c->requested_by, $c->employee_id);

        return DB::transaction(function () use ($c, $approve) {
            $c->update(['status' => $approve ? 'approved' : 'rejected', 'decided_by' => $this->ctx->actor()?->id, 'decided_at' => now()]);
            $this->bus->record('hr.pay_change_'.($approve ? 'approved' : 'rejected'), ['id' => $c->id, 'employee_id' => $c->employee_id], 'compensation_change', $c->id);
            if ($approve) {
                $e = $c->employee;
                $name = fn ($id) => $id ? (Position::find($id)?->title ?? '…') : '…';
                $m = fn ($v) => number_format((int) $v, 0, ',', ' ');
                $doc = $this->contracts->generate($e, 'avenant', null, null, [
                    'type' => $c->type, 'from_salary' => $m($c->from_salary), 'to_salary' => $m($c->to_salary),
                    'from_position' => $name($c->from_position_id), 'to_position' => $name($c->to_position_id),
                    'effective_date' => $c->effective_date->format('d/m/Y'),
                ]);
                $c->update(['econtract_id' => $doc->id]);
                if ($c->effective_date->lte(today())) {
                    $this->applyChange($c);
                }
            }

            return $c->fresh();
        });
    }

    public function applyChange(CompensationChange $c): void
    {
        $e = $c->employee;
        $e->update(['base_salary' => $c->to_salary, 'position_id' => $c->to_position_id]);
        $e->contracts()->where('status', 'active')->update(['base_salary' => $c->to_salary]);
        $this->history($e, $c->type, $c->id, $c->effective_date->toDateString());
        $c->update(['status' => 'applied', 'applied_at' => now()]);
        $this->bus->record('hr.pay_change_applied', ['id' => $c->id, 'employee_id' => $e->id, 'base_salary' => $c->to_salary], 'compensation_change', $c->id);
    }

    /** Apply approved changes and complete approved transfers whose effective date has come. */
    public function applyDue(): int
    {
        $n = 0;
        foreach (CompensationChange::where('status', 'approved')->whereDate('effective_date', '<=', today())->get() as $c) {
            DB::transaction(fn () => $this->applyChange($c));
            $n++;
        }
        $hotel = $this->ctx->hotelId();
        foreach (Transfer::where('status', 'approved')->whereDate('effective_date', '<=', today())->when($hotel, fn ($q) => $q->where('from_hotel_id', $hotel))->get() as $t) {
            $this->completeTransfer($t);
            $n++;
        }

        return $n;
    }

    public function history(Employee $e, string $source, ?int $sourceId, string $date): SalaryHistory
    {
        return SalaryHistory::create([
            'hotel_id' => $e->hotel_id, 'employee_id' => $e->id, 'base_salary' => $e->base_salary, 'position_id' => $e->position_id,
            'effective_date' => $date, 'source' => $source, 'source_id' => $sourceId, 'recorded_by' => $this->ctx->actor()?->id,
        ]);
    }

    // ---- Bonuses & allowances ------------------------------------------------

    public function addElement(Employee $e, array $data): PayElement
    {
        if ($data['kind'] === 'recurring' && isset($data['end_period']) && $data['end_period'] < $data['start_period']) {
            throw new DomainException('The end period is before the start period.');
        }

        return PayElement::create([
            'employee_id' => $e->id, 'code' => strtoupper($data['code']), 'name' => $data['name'], 'kind' => $data['kind'], 'amount' => $data['amount'],
            'taxable' => $data['taxable'] ?? true, 'cnps' => $data['cnps'] ?? true,
            'period' => $data['kind'] === 'one_off' ? $data['period'] : null,
            'start_period' => $data['kind'] === 'recurring' ? $data['start_period'] : null,
            'end_period' => $data['kind'] === 'recurring' ? ($data['end_period'] ?? null) : null,
            'created_by' => $this->ctx->actor()?->id,
        ]);
    }

    public function cancelElement(PayElement $p): PayElement
    {
        if ($p->kind === 'one_off' && $this->periodLocked($p->period)) {
            throw new DomainException('Payroll for this period is already approved.', 'invalid-state');
        }
        $p->update(['status' => 'cancelled']);

        return $p;
    }

    /**
     * Earnings for payroll: [{code, name, amount, taxable, cnps}] by employee id.
     *
     * @return array<int, array<int, array{code:string,name:string,amount:int,taxable:bool,cnps:bool}>>
     */
    public static function earningsFor(string $period): array
    {
        return PayElement::forPeriod($period)->orderBy('id')->get()->groupBy('employee_id')
            ->map(fn ($g) => $g->map(fn ($p) => ['code' => $p->code, 'name' => $p->name, 'amount' => (int) $p->amount, 'taxable' => (bool) $p->taxable, 'cnps' => (bool) $p->cnps])->values()->all())
            ->all();
    }

    /** Split a service-charge pool by points or days worked (largest remainder: shares add up exactly). */
    public function createPool(string $period, int $amount, string $method, array $entries): ServiceChargePool
    {
        $entries = collect($entries)->filter(fn ($x) => (float) $x['weight'] > 0)->values();
        if ($entries->isEmpty()) {
            throw new DomainException('Add at least one employee with points or days.');
        }
        $ids = $entries->pluck('employee_id')->unique();
        if (Employee::whereIn('id', $ids)->count() !== $ids->count()) {
            throw new DomainException('Unknown employee in the pool.');
        }
        $total = $entries->sum(fn ($x) => (float) $x['weight']);
        $shares = $entries->map(function ($x) use ($amount, $total) {
            $exact = $amount * (float) $x['weight'] / $total;

            return ['employee_id' => (int) $x['employee_id'], 'weight' => (float) $x['weight'], 'amount' => (int) floor($exact), 'frac' => $exact - floor($exact)];
        })->all();
        $left = $amount - array_sum(array_column($shares, 'amount'));
        $order = array_keys($shares);
        usort($order, fn ($a, $b) => $shares[$b]['frac'] <=> $shares[$a]['frac'] ?: $a <=> $b);
        foreach (array_slice($order, 0, $left) as $i) {
            $shares[$i]['amount']++;
        }

        return ServiceChargePool::create([
            'period' => $period, 'amount' => $amount, 'method' => $method, 'created_by' => $this->ctx->actor()?->id,
            'shares' => array_map(fn ($s) => array_diff_key($s, ['frac' => 1]), $shares),
        ]);
    }

    public function distributePool(ServiceChargePool $pool): ServiceChargePool
    {
        if ($pool->status !== 'draft') {
            throw new DomainException('This pool is already distributed.', 'invalid-state');
        }
        if ($this->periodLocked($pool->period)) {
            throw new DomainException('Payroll for this period is already approved.', 'invalid-state');
        }

        return DB::transaction(function () use ($pool) {
            foreach ($pool->shares as $s) {
                if ($s['amount'] > 0) {
                    PayElement::create([
                        'employee_id' => $s['employee_id'], 'code' => 'SVC', 'name' => 'Service (pourboires répartis)', 'kind' => 'one_off',
                        'amount' => $s['amount'], 'taxable' => true, 'cnps' => true, 'period' => $pool->period,
                        'service_charge_pool_id' => $pool->id, 'created_by' => $this->ctx->actor()?->id,
                    ]);
                }
            }
            $pool->update(['status' => 'distributed']);
            $this->bus->record('hr.service_charge_distributed', ['id' => $pool->id, 'period' => $pool->period, 'amount' => $pool->amount], 'service_charge_pool', $pool->id);

            return $pool;
        });
    }

    private function periodLocked(?string $period): bool
    {
        return $period && DB::table('payroll_runs')->where('hotel_id', $this->ctx->requireHotelId())->where('period', $period)->whereIn('status', ['approved', 'paid'])->exists();
    }

    // ---- Transfers -----------------------------------------------------------

    public function requestTransfer(Employee $e, array $data): Transfer
    {
        if ($e->status === 'terminated') {
            throw new DomainException('This employee has left.', 'invalid-state');
        }
        $to = Hotel::find($data['to_hotel_id']); // group-scoped
        if (! $to || $to->id === $e->hotel_id) {
            throw new DomainException('Choose another hotel of the group.', 'invalid-hotel');
        }
        $allowed = $this->ctx->actor()?->allowedHotelIds();
        if ($allowed !== null && ! in_array($to->id, $allowed, true)) {
            throw new DomainException('You do not have access to the destination hotel.', 'forbidden-hotel', 403);
        }
        $this->ctx->withoutScope(function () use ($data, $to) {
            if (! empty($data['to_department_id']) && ! Department::whereKey($data['to_department_id'])->where('hotel_id', $to->id)->exists()) {
                throw new DomainException('The department is not in the destination hotel.');
            }
            if (! empty($data['to_position_id']) && ! Position::whereKey($data['to_position_id'])->where('hotel_id', $to->id)->exists()) {
                throw new DomainException('The position is not in the destination hotel.');
            }
        });
        if (Transfer::where('employee_id', $e->id)->whereIn('status', ['pending', 'approved'])->exists()) {
            throw new DomainException('This employee already has a pending transfer.', 'duplicate');
        }
        $t = Transfer::create([
            'employee_id' => $e->id, 'from_hotel_id' => $e->hotel_id, 'to_hotel_id' => $to->id,
            'from_department_id' => $e->department_id, 'to_department_id' => $data['to_department_id'] ?? null,
            'from_position_id' => $e->position_id, 'to_position_id' => $data['to_position_id'] ?? null,
            'new_base_salary' => $data['new_base_salary'] ?? null, 'effective_date' => $data['effective_date'],
            'reason' => $data['reason'] ?? null, 'from_number' => $e->number, 'requested_by' => $this->ctx->actor()?->id,
        ]);
        $this->bus->record('hr.transfer_requested', ['id' => $t->id, 'employee_id' => $e->id, 'to_hotel_id' => $to->id], 'transfer', $t->id);

        return $t;
    }

    public function decideTransfer(Transfer $t, bool $approve): Transfer
    {
        if ($t->status !== 'pending') {
            throw new DomainException("This transfer is {$t->status}.", 'invalid-state');
        }
        $this->assertSod($t->requested_by, $t->employee_id);
        $t->update(['status' => $approve ? 'approved' : 'rejected', 'decided_by' => $this->ctx->actor()?->id, 'decided_at' => now()]);
        $this->bus->record('hr.transfer_'.($approve ? 'approved' : 'rejected'), ['id' => $t->id, 'employee_id' => $t->employee_id], 'transfer', $t->id);
        if ($approve && $t->effective_date->lte(today())) {
            $this->completeTransfer($t);
        }

        return $t->fresh();
    }

    /** Move the employee record: hire date (seniority), contracts, leave history and recurring pay follow. */
    public function completeTransfer(Transfer $t): Transfer
    {
        $saved = [$this->ctx->groupId(), $this->ctx->hotelId(), $this->ctx->allowedHotelIds(), $this->ctx->actor()];
        try {
            return DB::transaction(function () use ($t) {
                $this->ctx->set($t->group_id, $t->from_hotel_id, [$t->from_hotel_id], $this->ctx->actor());
                $e = Employee::lockForUpdate()->findOrFail($t->employee_id);
                $balance = $this->hr->leaveBalance($e);

                $this->ctx->set($t->group_id, $t->to_hotel_id, [$t->to_hotel_id], $this->ctx->actor());
                $number = NumberSequence::next($t->to_hotel_id, 'employee', 'E');
                $e->forceFill([
                    'hotel_id' => $t->to_hotel_id, 'number' => $number, 'reports_to' => null,
                    'department_id' => $t->to_department_id, 'position_id' => $t->to_position_id ?? null,
                    'base_salary' => $t->new_base_salary ?? $e->base_salary,
                ])->save();
                if ($t->new_base_salary) {
                    $e->contracts()->where('status', 'active')->update(['base_salary' => $t->new_base_salary]);
                }
                // History rows travel with the employee so balances and seniority stay intact.
                LeaveRequest::withoutGlobalScopes()->where('employee_id', $e->id)->whereIn('status', ['pending', 'approved'])->update(['hotel_id' => $t->to_hotel_id]);
                PayElement::withoutGlobalScopes()->where('employee_id', $e->id)->where('kind', 'recurring')->where('status', 'active')->update(['hotel_id' => $t->to_hotel_id]);
                DB::table('shifts')->where('employee_id', $e->id)->where('date', '>=', $t->effective_date)->delete();
                $this->history($e, 'transfer', $t->id, $t->effective_date->toDateString());

                $after = $this->hr->leaveBalance($e->fresh());
                if (abs($after - $balance) > 0.001) {
                    throw new DomainException("Leave balance would change on transfer ($balance → $after).", 'transfer-balance');
                }
                $t->update(['status' => 'completed', 'completed_at' => now(), 'seniority_date' => $e->hire_date, 'leave_balance' => $balance, 'to_number' => $number]);
                if ($e->user_id) {
                    DB::table('hotel_user')->updateOrInsert(['user_id' => $e->user_id, 'hotel_id' => $t->to_hotel_id]);
                }
                $this->bus->record('hr.employee_transferred', [
                    'id' => $t->id, 'employee_id' => $e->id, 'from_hotel_id' => $t->from_hotel_id, 'to_hotel_id' => $t->to_hotel_id,
                    'seniority_date' => $e->hire_date->toDateString(), 'leave_balance' => $balance,
                ], 'transfer', $t->id);

                return $t;
            });
        } finally {
            $this->ctx->set(...$saved);
        }
    }

    /** Requester cannot approve; nobody approves a change about themselves. */
    /** Four-eyes: the actor is neither the requester nor the employee concerned. Also used by HrController and SelfService. */
    public function assertSod(?int $requestedBy, int $employeeId): void
    {
        $actor = $this->ctx->actor();
        if ($requestedBy && $actor && $requestedBy === $actor->id) {
            throw new DomainException('The request must be approved by someone other than the requester.', 'sod');
        }
        $userId = $this->ctx->withoutScope(fn () => Employee::withoutGlobalScopes()->whereKey($employeeId)->value('user_id'));
        if ($actor && $userId && (int) $userId === $actor->id) {
            throw new DomainException('You cannot approve a change about yourself.', 'sod');
        }
    }
}
