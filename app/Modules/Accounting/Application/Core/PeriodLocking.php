<?php

namespace App\Modules\Accounting\Application\Core;

use App\Modules\Accounting\Application\Ledger;
use App\Modules\Accounting\Domain\ChartOfAccounts as C;
use App\Platform\Domain\DomainException;
use App\Platform\Events\EventBus;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Period close / reopen with a four-eyes approval: one user requests, a different user with
 * accounting.period_approve decides. Every request, decision and reopen reason is kept (audit log + this table).
 */
class PeriodLocking
{
    public function __construct(private Ledger $ledger, private TenantContext $ctx, private AuditTrail $audit, private EventBus $bus) {}

    public function list(?string $status = null): array
    {
        return DB::table('period_lock_requests as r')->leftJoin('users as u', 'u.id', '=', 'r.requested_by')->leftJoin('users as d', 'd.id', '=', 'r.decided_by')
            ->where('r.hotel_id', $this->ctx->requireHotelId())->when($status, fn ($q, $v) => $q->where('r.status', $v))
            ->orderByDesc('r.id')->get(['r.*', 'u.name as requester', 'd.name as decider'])->all();
    }

    public function request(int $year, int $month, string $action, ?string $reason): array
    {
        $h = $this->ctx->requireHotelId();
        if ($action === 'reopen' && ! trim((string) $reason)) {
            throw new DomainException('A reason is required to reopen a period.', 'reason-required');
        }
        $status = DB::table('fiscal_periods')->where(['hotel_id' => $h, 'year' => $year, 'month' => $month])->value('status') ?? 'open';
        if (($action === 'close' && $status === 'closed') || ($action === 'reopen' && $status !== 'closed')) {
            throw new DomainException("The period is already $status.", 'no-change');
        }
        if (DB::table('period_lock_requests')->where(['hotel_id' => $h, 'year' => $year, 'month' => $month, 'status' => 'pending'])->exists()) {
            throw new DomainException('A request is already pending for this period.', 'pending-exists');
        }
        $id = DB::table('period_lock_requests')->insertGetId(['group_id' => $this->ctx->groupId(), 'hotel_id' => $h, 'year' => $year, 'month' => $month, 'action' => $action,
            'reason' => $reason, 'requested_by' => $this->ctx->actor()->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->audit->record('lock_requested', 'fiscal_period', "$year-$month", ['action' => $action, 'reason' => $reason, 'request' => $id]);

        return (array) DB::table('period_lock_requests')->find($id);
    }

    public function decide(int $id, bool $approve, ?string $note = null): array
    {
        $r = DB::table('period_lock_requests')->where(['id' => $id, 'hotel_id' => $this->ctx->requireHotelId()])->first() ?? throw new DomainException('Request not found.', 'not-found');
        if ($r->status !== 'pending') {
            throw new DomainException('This request was already decided.', 'already-decided');
        }
        $actor = $this->ctx->actor();
        if ($actor->id === $r->requested_by) {
            throw new DomainException('The approver must be a different user than the requester.', 'four-eyes');
        }
        DB::transaction(function () use ($r, $approve, $note, $actor) {
            if ($approve) {
                $r->action === 'close' ? $this->close($r->year, $r->month, $actor->id) : $this->reopen($r->year, $r->month);
            }
            DB::table('period_lock_requests')->where('id', $r->id)->update(['status' => $approve ? 'approved' : 'rejected', 'decided_by' => $actor->id, 'decision_note' => $note, 'decided_at' => now(), 'updated_at' => now()]);
            $this->audit->record($approve ? ($r->action === 'close' ? 'period_closed' : 'period_reopened') : 'lock_rejected', 'fiscal_period', "{$r->year}-{$r->month}", ['request' => $r->id, 'requested_by' => $r->requested_by, 'reason' => $r->reason, 'note' => $note]);
        });

        return (array) DB::table('period_lock_requests')->find($id);
    }

    private function close(int $year, int $month, int $userId): void
    {
        $h = $this->ctx->requireHotelId();
        $end = Carbon::create($year, $month)->endOfMonth();
        $tb = $this->ledger->balances(null, $end->toDateString());
        if (array_sum(array_column($tb, 'debit')) !== array_sum(array_column($tb, 'credit'))) {
            throw new DomainException('Trial balance does not balance; the period cannot be closed.', 'unbalanced');
        }
        if (($tb[C::role('suspense')]['balance'] ?? 0) !== 0) {
            throw new DomainException('The suspense account (471) must be cleared before closing.', 'suspense');
        }
        DB::table('fiscal_periods')->updateOrInsert(['hotel_id' => $h, 'year' => $year, 'month' => $month], ['status' => 'closed', 'closed_at' => now(), 'closed_by' => $userId]);
        $this->bus->record('accounting.period_closed', ['year' => $year, 'month' => $month], 'hotel', $h);
    }

    private function reopen(int $year, int $month): void
    {
        $h = $this->ctx->requireHotelId();
        $this->ledger->assertYearNotLocked($h, Carbon::create($year, $month));
        DB::table('fiscal_periods')->where(['hotel_id' => $h, 'year' => $year, 'month' => $month])->update(['status' => 'open']);
        $this->bus->record('accounting.period_reopened', ['year' => $year, 'month' => $month], 'hotel', $h);
    }
}
