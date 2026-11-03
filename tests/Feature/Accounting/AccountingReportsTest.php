<?php

namespace Tests\Feature\Accounting;

use App\Modules\Property\Domain\Room;
use App\Modules\Reservations\Application\ReservationService;
use Tests\TestCase;

class AccountingReportsTest extends TestCase
{
    private function stay(): void
    {
        [$type, $plan, $guest] = $this->seedInventory(rooms: 1, rate: 59_625);
        $guest->update(['id_type' => 'passport', 'id_number' => 'CM1', 'nationality' => 'CM']);
        $r = app(ReservationService::class)->create([
            'guest_id' => $guest->id, 'room_type_id' => $type->id, 'rate_plan_id' => $plan->id,
            'arrival' => now()->toDateString(), 'departure' => now()->addDay()->toDateString(),
        ]);
        $room = Room::where('room_type_id', $type->id)->first();
        $this->postJson("/api/v1/reservations/{$r->id}/check-in", ['room_id' => $room->id])->assertOk();
        $this->postJson('/api/v1/night-audit')->assertOk();
        $folio = $this->getJson("/api/v1/reservations/{$r->id}/folio")->json();
        $this->postJson('/api/v1/cashier-shifts', ['opening_float' => 10_000])->assertCreated();
        $this->postJson("/api/v1/folios/{$folio['id']}/payments", ['method' => 'MTN', 'amount' => $folio['balance'], 'reference' => 'X'])->assertCreated();
    }

    public function test_balance_sheet_balances_after_a_stay(): void
    {
        $this->stay();
        $this->assertBooksBalance();
        $bs = $this->getJson('/api/v1/accounting/reports/balance-sheet?date='.now()->addYear()->toDateString())->assertOk()->json();

        $this->assertSame($bs['total_assets'], $bs['total_liabilities_and_equity']);
        $this->assertGreaterThan(0, $bs['total_assets']);
        foreach (array_merge($bs['assets'], $bs['liabilities_and_equity']) as $row) {
            $this->assertNotSame(0, $row['amount']);
        }
        $this->assertContains('131000', array_column($bs['liabilities_and_equity'], 'account'));
    }

    public function test_balance_sheet_omits_zero_result_line(): void
    {
        $bs = $this->getJson('/api/v1/accounting/reports/balance-sheet?date='.now()->toDateString())->assertOk()->json();
        $this->assertSame([], $bs['liabilities_and_equity']);
        $this->assertSame(0, $bs['total_liabilities_and_equity']);
    }

    public function test_journals_lists_configured_and_used_codes(): void
    {
        $this->stay();
        $rows = $this->getJson('/api/v1/accounting/journals')->assertOk()->json();
        $this->assertNotEmpty($rows);
        foreach ($rows as $r) {
            $this->assertArrayHasKey('code', $r);
            $this->assertArrayHasKey('name', $r);
        }
        $this->assertSame(count($rows), count(array_unique(array_column($rows, 'code'))));
    }
}
