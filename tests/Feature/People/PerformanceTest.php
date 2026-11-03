<?php

namespace Tests\Feature\People;

use App\Modules\People\Domain\Review;

class PerformanceTest extends PeopleTestCase
{
    private function openCycle(): array
    {
        $dept = $this->department();
        $manager = $this->employee(['department_id' => $dept->id]);
        $emp = $this->employee(['department_id' => $dept->id, 'reports_to' => $manager->id]);
        $cycle = $this->postJson('/api/v1/people/cycles', ['name' => '2026 H2', 'period_start' => '2026-07-01', 'period_end' => '2026-12-31'])
            ->assertCreated()->json();
        $this->postJson("/api/v1/people/cycles/{$cycle['id']}/open", ['department_id' => $dept->id])->assertOk()->assertJsonPath('status', 'open');
        $review = Review::where('employee_id', $emp->id)->firstOrFail();

        return [$cycle, $review, $emp, $manager, $dept];
    }

    public function test_full_review_flow_with_weighted_ratings_and_calibration_summary(): void
    {
        [$cycle, $review, , $manager] = $this->openCycle();
        $this->assertSame($manager->id, $review->manager_employee_id);
        $this->assertSame(2, Review::count());

        $this->putJson("/api/v1/people/reviews/{$review->id}/objectives", ['objectives' => [
            ['title' => 'Rooms cleaned on time', 'weight' => 60], ['title' => 'Guest score', 'weight' => 40],
        ]])->assertOk()->assertJsonCount(2, 'objectives');
        [$o1, $o2] = $review->objectives()->pluck('id')->all();

        // Self 4 and 5 => 4.4 ; manager 3 and 4 => 3.4
        $this->postJson("/api/v1/people/reviews/{$review->id}/self", ['scores' => [$o1 => 4, $o2 => 5], 'comment' => 'Bon semestre'])
            ->assertOk()->assertJsonPath('self_rating', 4.4)->assertJsonPath('status', 'self_done');
        $this->postJson("/api/v1/people/reviews/{$review->id}/manager", ['scores' => [$o1 => 3, $o2 => 4]])
            ->assertOk()->assertJsonPath('manager_rating', 3.4)->assertJsonPath('status', 'manager_done');

        // Calibration only once the cycle is in calibration.
        $this->postJson("/api/v1/people/reviews/{$review->id}/calibrate", ['rating' => 3.8])->assertStatus(422);
        $this->postJson("/api/v1/people/cycles/{$cycle['id']}/calibration")->assertOk();
        $this->postJson("/api/v1/people/reviews/{$review->id}/calibrate", ['rating' => 3.8, 'note' => 'Aligned'])->assertOk()->assertJsonPath('calibrated_rating', 3.8);

        $sum = $this->getJson("/api/v1/people/cycles/{$cycle['id']}/calibration-summary")->assertOk()->json('departments');
        $this->assertCount(1, $sum);
        $this->assertSame('Housekeeping', $sum[0]['department']);
        $this->assertSame(2, $sum[0]['reviews']);
        $this->assertSame(1, $sum[0]['completed']);
        $this->assertEquals(3.8, $sum[0]['avg_final']);
        $this->assertSame(1, $sum[0]['distribution'][4]);

        $this->postJson("/api/v1/people/cycles/{$cycle['id']}/close")->assertOk()->assertJsonPath('status', 'closed');
        $this->assertSame('closed', $review->fresh()->status);
    }

    public function test_objective_weights_must_total_100_and_manager_follows_self(): void
    {
        [, $review] = $this->openCycle();
        $this->putJson("/api/v1/people/reviews/{$review->id}/objectives", ['objectives' => [['title' => 'A', 'weight' => 50], ['title' => 'B', 'weight' => 30]]])
            ->assertStatus(422)->assertJsonPath('type', 'https://docs.opeshotelsoft.test/problems/people-objective-weights');
        $this->putJson("/api/v1/people/reviews/{$review->id}/objectives", ['objectives' => [['title' => 'A', 'weight' => 100]]])->assertOk();
        $oid = $review->objectives()->value('id');
        $this->postJson("/api/v1/people/reviews/{$review->id}/manager", ['scores' => [$oid => 3]])->assertStatus(422);
        $this->postJson("/api/v1/people/reviews/{$review->id}/self", ['scores' => [$oid => 9]])->assertStatus(422);
    }

    public function test_employee_does_own_self_review_and_manager_rates_without_hr_permission(): void
    {
        $dept = $this->department();
        $mgrUser = $this->makeUser($this->group, 'room-attendant', hotels: [$this->hotel]);
        $empUser = $this->makeUser($this->group, 'room-attendant', hotels: [$this->hotel]);
        $manager = $this->employee(['department_id' => $dept->id], $mgrUser);
        $emp = $this->employee(['department_id' => $dept->id, 'reports_to' => $manager->id], $empUser);
        $cycle = $this->postJson('/api/v1/people/cycles', ['name' => 'C', 'period_start' => '2026-01-01', 'period_end' => '2026-12-31'])->json();
        $this->postJson("/api/v1/people/cycles/{$cycle['id']}/open")->assertOk();
        $review = Review::where('employee_id', $emp->id)->first();

        $this->actAs($mgrUser, $this->hotel);
        $this->putJson("/api/v1/people/reviews/{$review->id}/objectives", ['objectives' => [['title' => 'A', 'weight' => 100]]])->assertOk();
        $oid = $review->objectives()->value('id');
        $this->postJson("/api/v1/people/reviews/{$review->id}/self", ['scores' => [$oid => 4]])->assertForbidden();
        $this->getJson('/api/v1/people/cycles')->assertForbidden();

        $this->actAs($empUser, $this->hotel);
        $this->assertCount(1, $this->getJson('/api/v1/people/reviews/mine')->assertOk()->json('as_employee'));
        $this->postJson("/api/v1/people/reviews/{$review->id}/self", ['scores' => [$oid => 4]])->assertOk();
        $this->postJson("/api/v1/people/reviews/{$review->id}/manager", ['scores' => [$oid => 4]])->assertForbidden();

        $this->actAs($mgrUser, $this->hotel);
        $this->postJson("/api/v1/people/reviews/{$review->id}/manager", ['scores' => [$oid => 5]])->assertOk()->assertJsonPath('manager_rating', 5);
    }
}
