<?php

namespace Tests\Feature\Guests;

use App\Modules\Guests\Domain\Company;
use App\Modules\Guests\Domain\Guest;
use App\Modules\Reservations\Application\ReservationService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class GuestApiTest extends TestCase
{
    public function test_merge_into_itself_is_a_clean_problem(): void
    {
        $g = Guest::create(['first_name' => 'A', 'last_name' => 'B']);
        $this->postJson("/api/v1/guests/{$g->id}/merge", ['duplicate_id' => $g->id])
            ->assertStatus(422)->assertJsonPath('type', 'https://docs.opeshotelsoft.test/problems/guest-self-merge');
    }

    public function test_merge_moves_reservations_folios_and_loyalty(): void
    {
        [$type, $plan, $survivor] = $this->seedInventory();
        $dup = Guest::create(['first_name' => 'Jean', 'last_name' => 'Mballa', 'phone' => '+237600']);
        $r = app(ReservationService::class)->create([
            'guest_id' => $dup->id, 'room_type_id' => $type->id, 'rate_plan_id' => $plan->id,
            'arrival' => now()->toDateString(), 'departure' => now()->addDay()->toDateString(),
        ]);
        $folioId = DB::table('folios')->insertGetId([
            'group_id' => $this->group->id, 'hotel_id' => $this->hotel->id, 'number' => 'TST-1', 'guest_id' => $dup->id,
            'name' => 'Jean', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $programId = DB::table('loyalty_programs')->insertGetId(['group_id' => $this->group->id, 'name' => 'P', 'created_at' => now(), 'updated_at' => now()]);
        $tierId = DB::table('loyalty_tiers')->insertGetId(['loyalty_program_id' => $programId, 'code' => 'S', 'name' => 'Silver', 'created_at' => now(), 'updated_at' => now()]);
        $memberId = DB::table('loyalty_memberships')->insertGetId([
            'group_id' => $this->group->id, 'guest_id' => $dup->id, 'number' => 'L1', 'loyalty_tier_id' => $tierId,
            'points_balance' => 50, 'enrolled_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->postJson("/api/v1/guests/{$survivor->id}/merge", ['duplicate_id' => $dup->id])->assertOk()->assertJsonPath('phone', '+237600');

        $this->assertSame($survivor->id, DB::table('reservations')->where('id', $r->id)->value('guest_id'));
        $this->assertSame($survivor->id, DB::table('folios')->where('id', $folioId)->value('guest_id'));
        $this->assertSame($survivor->id, DB::table('loyalty_memberships')->where('id', $memberId)->value('guest_id'));
        $this->assertSoftDeleted('guests', ['id' => $dup->id]);
    }

    public function test_blacklist_requires_reason_on_store_and_update(): void
    {
        $this->postJson('/api/v1/guests', ['first_name' => 'X', 'last_name' => 'Y', 'blacklisted' => true])
            ->assertStatus(422)->assertJsonValidationErrors('blacklist_reason');
        $g = $this->postJson('/api/v1/guests', ['first_name' => 'X', 'last_name' => 'Y', 'blacklisted' => true, 'blacklist_reason' => 'Fraud'])
            ->assertCreated()->json();
        $this->putJson("/api/v1/guests/{$g['id']}", ['blacklisted' => true, 'blacklist_reason' => null])
            ->assertStatus(422)->assertJsonValidationErrors('blacklist_reason');
        $this->putJson("/api/v1/guests/{$g['id']}", ['blacklisted' => false])->assertOk();
    }

    public function test_export_is_an_attachment(): void
    {
        $g = Guest::create(['first_name' => 'A', 'last_name' => 'B']);
        $res = $this->get("/api/v1/guests/{$g->id}/export")->assertOk();
        $this->assertSame('attachment; filename="guest-'.$g->id.'-export.json"', $res->headers->get('Content-Disposition'));
        $res->assertJsonPath('profile.id', $g->id);
    }

    public function test_company_link_is_validated_within_tenant_and_returned(): void
    {
        $company = Company::create(['name' => 'MTN Cameroon']);
        [$otherGroup] = $this->makeHotel('Other', 'OTH');
        $foreign = app(\App\Platform\Tenancy\TenantContext::class)->withoutScope(
            fn () => Company::forceCreate(['group_id' => $otherGroup->id, 'name' => 'Foreign'])
        );

        $this->postJson('/api/v1/guests', ['first_name' => 'X', 'last_name' => 'Y', 'company_id' => $foreign->id])
            ->assertStatus(422)->assertJsonValidationErrors('company_id');
        $g = $this->postJson('/api/v1/guests', ['first_name' => 'X', 'last_name' => 'Y', 'company_id' => $company->id])
            ->assertCreated()->assertJsonPath('company.name', 'MTN Cameroon')->json();
        $this->getJson("/api/v1/guests/{$g['id']}")->assertJsonPath('company.id', $company->id);
        $this->getJson('/api/v1/guests')->assertJsonPath('data.0.company.name', 'MTN Cameroon');
    }
}
