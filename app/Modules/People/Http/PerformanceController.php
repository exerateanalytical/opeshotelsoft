<?php

namespace App\Modules\People\Http;

use App\Modules\People\Application\PerformanceService;
use App\Modules\People\Domain\Review;
use App\Modules\People\Domain\ReviewCycle;
use Illuminate\Http\Request;

class PerformanceController
{
    use Authorizes;

    public function __construct(private PerformanceService $perf) {}

    public function cycles(Request $request)
    {
        $this->need($request, 'people.view', 'people.manage');

        return ReviewCycle::withCount('reviews')->orderByDesc('period_start')->get();
    }

    public function storeCycle(Request $request)
    {
        $this->need($request, 'people.manage');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'], 'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after:period_start'], 'scale' => ['sometimes', 'integer', 'in:4,5,10'],
        ]);

        return response()->json($this->perf->createCycle($data), 201);
    }

    public function showCycle(Request $request, ReviewCycle $cycle)
    {
        $this->need($request, 'people.view', 'people.manage');
        $reviews = $this->withNames($cycle->reviews()->withCount('objectives')->orderBy('id')->get(), 'employee_id', 'manager_employee_id');

        return ['cycle' => $cycle, 'reviews' => $reviews];
    }

    public function open(Request $request, ReviewCycle $cycle)
    {
        $this->need($request, 'people.manage');
        $data = $request->validate(['department_id' => ['nullable', 'integer'], 'employee_ids' => ['sometimes', 'array'], 'employee_ids.*' => ['integer']]);

        return $this->perf->open($cycle, $data['department_id'] ?? null, $data['employee_ids'] ?? []);
    }

    public function startCalibration(Request $request, ReviewCycle $cycle)
    {
        $this->need($request, 'people.manage');

        return $this->perf->startCalibration($cycle);
    }

    public function close(Request $request, ReviewCycle $cycle)
    {
        $this->need($request, 'people.manage');

        return $this->perf->close($cycle);
    }

    public function summary(Request $request, ReviewCycle $cycle)
    {
        $this->need($request, 'people.view', 'people.manage');

        return ['cycle' => $cycle, 'departments' => $this->perf->calibrationSummary($cycle)];
    }

    /** Reviews where I am the employee or the manager. */
    public function mine(Request $request)
    {
        $me = $this->me($request);
        if (! $me) {
            return ['as_employee' => [], 'as_manager' => []];
        }
        $base = fn () => Review::with('cycle:id,name,status,scale')->whereHas('cycle', fn ($q) => $q->whereIn('status', ['open', 'calibration']));

        return [
            'as_employee' => $this->withNames($base()->where('employee_id', $me->id)->get(), 'employee_id'),
            'as_manager' => $this->withNames($base()->where('manager_employee_id', $me->id)->get(), 'employee_id'),
        ];
    }

    public function showReview(Request $request, Review $review)
    {
        $this->canSee($request, $review);

        return $this->withNames(collect([$review->load(['objectives', 'cycle'])]), 'employee_id', 'manager_employee_id')->first();
    }

    public function objectives(Request $request, Review $review)
    {
        $this->canManage($request, $review);
        $data = $request->validate([
            'objectives' => ['required', 'array', 'min:1', 'max:15'], 'objectives.*.title' => ['required', 'string', 'max:200'],
            'objectives.*.weight' => ['required', 'integer', 'min:1', 'max:100'], 'objectives.*.description' => ['nullable', 'string'],
        ]);

        return $this->perf->setObjectives($review, $data['objectives']);
    }

    public function self(Request $request, Review $review)
    {
        if ($this->me($request)?->id !== $review->employee_id) {
            $this->need($request, 'people.manage');
        }
        $data = $this->scores($request);

        return $this->perf->submitSelf($review, $data['scores'], $data['comment'] ?? null);
    }

    public function manager(Request $request, Review $review)
    {
        $this->canManage($request, $review);
        $data = $this->scores($request);

        return $this->perf->submitManager($review, $data['scores'], $data['comment'] ?? null);
    }

    public function calibrate(Request $request, Review $review)
    {
        $this->need($request, 'people.manage');
        $data = $request->validate(['rating' => ['required', 'numeric'], 'note' => ['nullable', 'string', 'max:255']]);

        return $this->perf->calibrate($review, (float) $data['rating'], $data['note'] ?? null);
    }

    private function scores(Request $request): array
    {
        return $request->validate(['scores' => ['required', 'array'], 'scores.*' => ['numeric'], 'comment' => ['nullable', 'string', 'max:5000']]);
    }

    private function canSee(Request $request, Review $review): void
    {
        $me = $this->me($request)?->id;
        if ($me === null || ($me !== $review->employee_id && $me !== $review->manager_employee_id)) {
            $this->need($request, 'people.view', 'people.manage');
        }
    }

    private function canManage(Request $request, Review $review): void
    {
        $me = $this->me($request)?->id;
        if ($me === null || $me !== $review->manager_employee_id) {
            $this->need($request, 'people.manage');
        }
    }
}
