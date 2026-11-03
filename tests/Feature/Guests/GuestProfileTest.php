<?php

namespace Tests\Feature\Guests;

use App\Modules\Guests\Domain\Company;
use App\Modules\Guests\Domain\Guest;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class GuestProfileTest extends TestCase
{
    use RepairsRoleSeed;

    private function reservationRow(int $hotelId, int $guestId, array $extra = []): int
    {
        $ctx = app(TenantContext::class);

        return $ctx->withoutScope(function () use ($hotelId, $guestId, $extra) {
            $typeId = DB::table('room_types')->insertGetId(['group_id' => $this->group->id, 'hotel_id' => $hotelId, 'code' => 'T'.substr(uniqid(), -8), 'name' => 'Std', 'base_rate' => 1000, 'created_at' => now(), 'updated_at' => now()]);
            $planId = DB::table('rate_plans')->insertGetId(['group_id' => $this->group->id, 'hotel_id' => $hotelId, 'code' => 'P'.substr(uniqid(), -8), 'name' => 'Plan', 'company_id' => $extra['plan_company_id'] ?? null, 'created_at' => now(), 'updated_at' => now()]);
            unset($extra['plan_company_id']);

            return DB::table('reservations')->insertGetId($extra + [
                'group_id' => $this->group->id, 'hotel_id' => $hotelId, 'number' => 'R'.uniqid(), 'booking_ref' => 'B'.substr(uniqid(), -8),
                'guest_id' => $guestId, 'room_type_id' => $typeId, 'rate_plan_id' => $planId,
                'arrival' => now()->toDateString(), 'departure' => now()->addDays(2)->toDateString(), 'total_amount' => 100_000,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        });
    }

    public function test_history_spans_group_hotels_but_hotel_scoped_user_sees_only_theirs(): void
    {
        [, $sister] = $this->makeHotel('Akwa Test', 'BON', $this->group);
        [$otherGroup, $foreign] = $this->makeHotel('Other Group', 'OTH');
        $guest = Guest::create(['first_name' => 'Awa', 'last_name' => 'Ngo']);
        $this->reservationRow($this->hotel->id, $guest->id);
        $this->reservationRow($sister->id, $guest->id);
        DB::table('folios')->insert(['group_id' => $this->group->id, 'hotel_id' => $sister->id, 'number' => 'F-1', 'guest_id' => $guest->id, 'name' => 'Awa', 'balance' => 25_000, 'created_at' => now(), 'updated_at' => now()]);

        // All-hotels manager on the active hotel still sees the sister hotel's stay.
        $this->getJson("/api/v1/guests/{$guest->id}/history")->assertOk()->assertJsonCount(2);
        $this->getJson("/api/v1/guests/{$guest->id}/folios")->assertOk()->assertJsonPath('open_balance', 25_000);

        // A receptionist limited to the main hotel sees only that hotel.
        $clerk = $this->makeUser($this->group, 'receptionist', hotels: [$this->hotel]);
        $this->actAs($clerk, $this->hotel);
        $rows = $this->getJson("/api/v1/guests/{$guest->id}/history")->assertOk()->json();
        $this->assertCount(1, $rows);
        $this->assertSame($this->hotel->id, $rows[0]['hotel_id']);
        $this->getJson("/api/v1/guests/{$guest->id}/folios")->assertOk()->assertJsonCount(0, 'folios');

        // Another group's user cannot see the guest at all.
        $stranger = $this->makeUser($otherGroup, 'general-manager', allHotels: true);
        $this->actAs($stranger, $foreign)->getJson("/api/v1/guests/{$guest->id}/history")->assertNotFound();

        // Without a guests permission: 403.
        $tech = $this->makeUser($this->group, 'maintenance-technician', allHotels: true);
        $this->actAs($tech, $this->hotel)->getJson("/api/v1/guests/{$guest->id}/history")->assertForbidden();
    }

    public function test_loyalty_summary_is_read_only(): void
    {
        $guest = Guest::create(['first_name' => 'Awa', 'last_name' => 'Ngo']);
        $this->getJson("/api/v1/guests/{$guest->id}/loyalty")->assertOk()->assertJsonPath('membership', null);
        $programId = DB::table('loyalty_programs')->insertGetId(['group_id' => $this->group->id, 'name' => 'P', 'created_at' => now(), 'updated_at' => now()]);
        $tierId = DB::table('loyalty_tiers')->insertGetId(['loyalty_program_id' => $programId, 'code' => 'G', 'name' => 'Gold', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('loyalty_memberships')->insert(['group_id' => $this->group->id, 'guest_id' => $guest->id, 'number' => 'L9', 'loyalty_tier_id' => $tierId, 'points_balance' => 1200, 'enrolled_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $this->getJson("/api/v1/guests/{$guest->id}/loyalty")->assertOk()
            ->assertJsonPath('membership.tier', 'Gold')->assertJsonPath('membership.points_balance', 1200);
    }

    public function test_company_overview_contacts_rates_bookings_and_statement(): void
    {
        $company = Company::create(['name' => 'SONARA', 'city_ledger' => true]);
        $guest = Guest::create(['first_name' => 'Paul', 'last_name' => 'Eto']);
        $this->reservationRow($this->hotel->id, $guest->id, ['company_id' => $company->id, 'plan_company_id' => $company->id]);

        $c = $this->postJson("/api/v1/companies/{$company->id}/contacts", ['name' => 'Marie Booker', 'role' => 'booker', 'email' => 'm@sonara.cm', 'primary' => true])
            ->assertCreated()->json();
        $this->patchJson("/api/v1/companies/{$company->id}/contacts/{$c['id']}", ['phone' => '+237 699'])->assertOk()->assertJsonPath('phone', '+237 699');

        $o = $this->getJson("/api/v1/companies/{$company->id}/overview")->assertOk();
        $o->assertJsonCount(1, 'contacts')->assertJsonCount(1, 'rate_plans')->assertJsonCount(1, 'bookings')
            ->assertJsonPath('bookings.0.guest_name', 'Paul Eto')
            ->assertJsonPath('statement_url', "receivables/companies/{$company->id}/statement");

        $this->deleteJson("/api/v1/companies/{$company->id}/contacts/{$c['id']}")->assertNoContent();

        $viewer = $this->makeUser($this->group, 'pos-cashier', allHotels: true); // guests.view only
        $this->actAs($viewer, $this->hotel);
        $this->getJson("/api/v1/companies/{$company->id}/overview")->assertOk();
        $this->postJson("/api/v1/companies/{$company->id}/contacts", ['name' => 'X'])->assertForbidden();
    }
}
