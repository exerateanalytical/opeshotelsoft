<?php

namespace App\Modules\HR\Talent\Application;

use App\Modules\HR\Domain\Employee;
use App\Modules\HR\Domain\PayrollRun;
use App\Modules\HR\Talent\Domain\PayrollReview;
use App\Modules\HR\Talent\Domain\TalentSettings;
use App\Platform\Domain\DomainException;
use App\Platform\Tenancy\TenantContext;

/**
 * Variance and audit checks run before a payroll is approved. The review is a checklist: flagged
 * checks are acknowledged by a reviewer. Approval is blocked only when the hotel sets payroll_review_required.
 */
class PayrollChecks
{
    public function __construct(private TenantContext $ctx) {}

    public function settings(): TalentSettings
    {
        return TalentSettings::firstOrCreate(['hotel_id' => $this->ctx->requireHotelId()]);
    }

    /** @return array<int, array{code:string,severity:string,items:array}> */
    public function compute(PayrollRun $run): array
    {
        $s = $this->settings();
        $slips = $run->payslips()->get()->keyBy('employee_id');
        $emps = Employee::whereIn('id', $slips->keys())->get()->keyBy('id');
        $prev = PayrollRun::where('period', '<', $run->period)->orderByDesc('period')->first();
        $prevSlips = $prev ? $prev->payslips()->get()->keyBy('employee_id') : collect();
        $name = fn ($id) => $emps[$id]->full_name ?? Employee::find($id)?->full_name ?? '#'.$id;
        $item = fn ($id, $detail) => ['employee_id' => $id, 'name' => $name($id), 'detail' => $detail];

        $new = $leaving = $big = $bank = $neg = $overtime = [];
        if ($prev) {
            foreach ($slips as $id => $_) {
                if (! $prevSlips->has($id)) {
                    $new[] = $item($id, 'new');
                }
            }
            foreach ($prevSlips as $id => $_) {
                if (! $slips->has($id)) {
                    $leaving[] = $item($id, 'left');
                }
            }
            foreach ($slips as $id => $sl) {
                $p = $prevSlips[$id] ?? null;
                if ($p && $p->net > 0) {
                    $pct = ($sl->net - $p->net) / $p->net * 100;
                    if (abs($pct) >= $s->variance_pct) {
                        $big[] = $item($id, sprintf('%+.1f%% (%d -> %d)', $pct, $p->net, $sl->net));
                    }
                }
            }
        }
        foreach ($slips as $id => $sl) {
            $e = $emps[$id] ?? null;
            if ($e && (($e->payment_method === 'bank' && ! $e->bank_account) || ($e->payment_method === 'mobile_money' && ! $e->momo_number))) {
                $bank[] = $item($id, $e->payment_method);
            }
            if ($sl->net <= 0 && $sl->gross > 0) {
                $neg[] = $item($id, (string) $sl->net);
            }
        }
        $accounts = $emps->filter(fn ($e) => $e->payment_method === 'bank' && $e->bank_account)
            ->groupBy(fn ($e) => preg_replace('/\s+/', '', strtolower($e->bank_account)))->filter(fn ($g) => $g->count() > 1);
        $dup = [];
        foreach ($accounts as $acc => $g) {
            foreach ($g as $e) {
                $dup[] = $item($e->id, (string) $acc);
            }
        }
        $hours = $slips->map(fn ($sl) => (float) ($sl->inputs['overtime_hours'] ?? 0))->filter(fn ($h) => $h > 0);
        if ($hours->count() >= 1) {
            $sorted = $hours->sort()->values();
            $median = $sorted[intdiv($sorted->count(), 2)];
            $limit = $hours->count() >= 3 ? max($median * $s->overtime_multiple, 10) : 60.0;
            foreach ($hours as $id => $h) {
                if ($h > $limit || $h > 80) {
                    $overtime[] = $item($id, $h.' h');
                }
            }
        }

        return [
            ['code' => 'new_employees', 'severity' => 'info', 'items' => $new],
            ['code' => 'leaving_employees', 'severity' => 'info', 'items' => $leaving],
            ['code' => 'big_changes', 'severity' => 'warning', 'items' => $big],
            ['code' => 'missing_bank', 'severity' => 'error', 'items' => $bank],
            ['code' => 'negative_net', 'severity' => 'error', 'items' => $neg],
            ['code' => 'duplicate_bank', 'severity' => 'error', 'items' => $dup],
            ['code' => 'overtime_outliers', 'severity' => 'warning', 'items' => $overtime],
        ];
    }

    /** Refresh the checklist (acknowledgements of still-flagged checks are kept). */
    public function review(PayrollRun $run): PayrollReview
    {
        $checks = $this->compute($run);
        $r = PayrollReview::firstOrNew(['payroll_run_id' => $run->id]);
        $flagged = collect($checks)->filter(fn ($c) => $c['items'])->pluck('code')->all();
        $r->fill(['checks' => $checks, 'acknowledged' => array_values(array_intersect($r->acknowledged ?? [], $flagged))])->save();
        if (! $flagged && ! $r->reviewed_at) {
            $r->update(['reviewed_by' => $this->ctx->actor()?->id, 'reviewed_at' => now()]);
        }

        return $r;
    }

    public function acknowledge(PayrollRun $run, array $codes, ?string $comment): PayrollReview
    {
        if ($run->status !== 'draft') {
            throw new DomainException("Payroll {$run->period} is {$run->status}.", 'invalid-state');
        }
        $r = $this->review($run);
        $flagged = collect($r->checks)->filter(fn ($c) => $c['items'])->pluck('code')->all();
        $ack = array_values(array_unique(array_merge($r->acknowledged, array_intersect($codes, $flagged))));
        $done = ! array_diff($flagged, $ack);
        $r->update(['acknowledged' => $ack, 'comment' => $comment ?? $r->comment, 'reviewed_by' => $done ? $this->ctx->actor()?->id : null, 'reviewed_at' => $done ? now() : null]);

        return $r;
    }

    /** Codes of flagged checks nobody has acknowledged yet. */
    public function pending(PayrollRun $run): array
    {
        $r = $this->review($run);

        return array_values(array_diff(collect($r->checks)->filter(fn ($c) => $c['items'])->pluck('code')->all(), $r->acknowledged));
    }

    /** Called by PayrollService::approve. Never blocks unless the hotel configured it. */
    public function assertReviewed(PayrollRun $run): void
    {
        $s = TalentSettings::where('hotel_id', $run->hotel_id)->first();
        if (! $s || ! $s->payroll_review_required) {
            return;
        }
        if ($pending = $this->pending($run)) {
            throw new DomainException('Review the payroll checks before approval: '.implode(', ', $pending), 'payroll-review-pending');
        }
    }
}
