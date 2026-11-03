<?php

namespace App\Modules\People\Application;

use App\Modules\HR\Domain\Employee;
use App\Modules\People\Domain\Objective;
use App\Modules\People\Domain\Review;
use App\Modules\People\Domain\ReviewCycle;
use App\Platform\Domain\DomainException;
use App\Platform\Events\EventBus;
use Illuminate\Support\Facades\DB;

/**
 * Review cycles: objectives with weights (sum 100), self review then manager
 * review (weighted average of objective scores), calibration per department.
 */
class PerformanceService
{
    public function __construct(private EventBus $bus, private EmployeeDirectory $people) {}

    public function createCycle(array $data): ReviewCycle
    {
        return ReviewCycle::create($data + ['status' => 'draft']);
    }

    /** Open the cycle and create one review per active employee (optionally only one department). */
    public function open(ReviewCycle $cycle, ?int $departmentId = null, array $employeeIds = []): ReviewCycle
    {
        if (! in_array($cycle->status, ['draft', 'open'], true)) {
            throw new DomainException('Only a draft or open cycle can take new reviews.', 'people-cycle-status');
        }

        return DB::transaction(function () use ($cycle, $departmentId, $employeeIds) {
            $q = Employee::where('status', '!=', 'terminated')
                ->when($departmentId, fn ($q) => $q->where('department_id', $departmentId))
                ->when($employeeIds, fn ($q) => $q->whereIn('id', $employeeIds));
            foreach ($q->get() as $e) {
                Review::firstOrCreate(['cycle_id' => $cycle->id, 'employee_id' => $e->id],
                    ['manager_employee_id' => $e->reports_to, 'department_id' => $e->department_id, 'status' => 'pending']);
            }
            $cycle->update(['status' => 'open']);
            $this->bus->record('people.review_cycle_opened', ['id' => $cycle->id, 'reviews' => $cycle->reviews()->count()], 'review_cycle', $cycle->id);

            return $cycle->fresh();
        });
    }

    /** @param array<int, array{title:string, weight:int, description?:string|null}> $items */
    public function setObjectives(Review $review, array $items): Review
    {
        $this->assertCycle($review, ['draft', 'open']);
        if ($review->status !== 'pending') {
            throw new DomainException('Objectives are locked once the self review is submitted.', 'people-review-locked');
        }
        if ((int) collect($items)->sum('weight') !== 100) {
            throw new DomainException('Objective weights must add up to 100.', 'people-objective-weights');
        }

        return DB::transaction(function () use ($review, $items) {
            $review->objectives()->delete();
            foreach ($items as $i) {
                Objective::create(['review_id' => $review->id, 'title' => $i['title'], 'description' => $i['description'] ?? null, 'weight' => (int) $i['weight']]);
            }

            return $review->load('objectives');
        });
    }

    /** @param array<int|string, float|int> $scores objective id => score */
    public function submitSelf(Review $review, array $scores, ?string $comment): Review
    {
        $this->assertCycle($review, ['open']);
        if ($review->status !== 'pending') {
            throw new DomainException('Self review already submitted.', 'people-review-status');
        }
        $rating = $this->score($review, $scores, 'self_score');
        $review->update(['self_rating' => $rating, 'self_comment' => $comment, 'self_submitted_at' => now(), 'status' => 'self_done']);

        return $review->load('objectives');
    }

    public function submitManager(Review $review, array $scores, ?string $comment): Review
    {
        $this->assertCycle($review, ['open', 'calibration']);
        if ($review->status !== 'self_done') {
            throw new DomainException('The manager review follows the self review.', 'people-review-status');
        }
        $rating = $this->score($review, $scores, 'manager_score');
        $review->update(['manager_rating' => $rating, 'manager_comment' => $comment, 'manager_submitted_at' => now(), 'status' => 'manager_done']);
        $this->bus->record('people.review_completed', ['id' => $review->id, 'employee_id' => $review->employee_id, 'rating' => $rating], 'review', $review->id);

        return $review->load('objectives');
    }

    public function startCalibration(ReviewCycle $cycle): ReviewCycle
    {
        if ($cycle->status !== 'open') {
            throw new DomainException('Only an open cycle can move to calibration.', 'people-cycle-status');
        }
        $cycle->update(['status' => 'calibration']);

        return $cycle;
    }

    public function calibrate(Review $review, float $rating, ?string $note): Review
    {
        $this->assertCycle($review, ['calibration']);
        if ($review->status !== 'manager_done') {
            throw new DomainException('Only reviews with a manager rating can be calibrated.', 'people-review-status');
        }
        if ($rating < 1 || $rating > $review->cycle->scale) {
            throw new DomainException('Rating outside the scale.', 'people-rating-range');
        }
        $review->update(['calibrated_rating' => round($rating, 2), 'calibration_note' => $note]);

        return $review;
    }

    public function close(ReviewCycle $cycle): ReviewCycle
    {
        if ($cycle->status !== 'calibration') {
            throw new DomainException('Calibrate before closing the cycle.', 'people-cycle-status');
        }
        DB::transaction(function () use ($cycle) {
            $cycle->reviews()->where('status', 'manager_done')->update(['status' => 'closed']);
            $cycle->update(['status' => 'closed']);
        });

        return $cycle->fresh();
    }

    /** Per department: counts, averages and rating distribution (rounded final rating). */
    public function calibrationSummary(ReviewCycle $cycle): array
    {
        $reviews = $cycle->reviews()->get();
        $names = \App\Modules\HR\Domain\Department::whereIn('id', $reviews->pluck('department_id')->filter()->unique())->pluck('name', 'id');
        $avg = fn ($c) => $c->filter(fn ($v) => $v !== null)->isEmpty() ? null : round($c->filter(fn ($v) => $v !== null)->avg(), 2);

        return $reviews->groupBy(fn ($r) => $r->department_id ?? 0)->map(function ($rows, $dept) use ($names, $avg, $cycle) {
            $finals = $rows->map->finalRating()->filter(fn ($v) => $v !== null);
            $dist = array_fill_keys(range(1, $cycle->scale), 0);
            foreach ($finals as $f) {
                $dist[(int) min($cycle->scale, max(1, round($f)))]++;
            }

            return [
                'department_id' => $dept ?: null, 'department' => $names[$dept] ?? null,
                'reviews' => $rows->count(), 'completed' => $rows->whereIn('status', ['manager_done', 'closed'])->count(),
                'avg_self' => $avg($rows->pluck('self_rating')), 'avg_manager' => $avg($rows->pluck('manager_rating')),
                'avg_final' => $avg($finals), 'distribution' => $dist,
            ];
        })->values()->all();
    }

    private function score(Review $review, array $scores, string $column): float
    {
        $objectives = $review->objectives()->get();
        if ($objectives->isEmpty()) {
            throw new DomainException('Set objectives before rating.', 'people-no-objectives');
        }
        $scale = $review->cycle->scale;
        $total = 0.0;
        foreach ($objectives as $o) {
            $s = $scores[$o->id] ?? null;
            if ($s === null || $s < 1 || $s > $scale) {
                throw new DomainException("Each objective needs a score from 1 to $scale.", 'people-rating-range');
            }
            $o->update([$column => $s]);
            $total += $s * $o->weight;
        }

        return round($total / 100, 2);
    }

    private function assertCycle(Review $review, array $statuses): void
    {
        if (! in_array($review->cycle->status, $statuses, true)) {
            throw new DomainException('The review cycle is '.$review->cycle->status.'.', 'people-cycle-status');
        }
    }
}
