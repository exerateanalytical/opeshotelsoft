<?php

namespace Tests\Feature\Guests;

use App\Modules\Guests\Domain\Company;
use App\Modules\Guests\Domain\CompanyContact;
use App\Modules\Guests\Domain\Guest;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DataProtectionTest extends TestCase
{
    use RepairsRoleSeed;

    public function test_anonymise_needs_second_approver_and_keeps_financial_rows(): void
    {
        [$type, $plan] = $this->seedInventory();
        $guest = Guest::create(['first_name' => 'Brice', 'last_name' => 'Kamga', 'email' => 'brice@test.cm', 'phone' => '+237677', 'id_number' => 'CNI123', 'notes' => 'Allergic']);
        $res = DB::table('reservations')->insertGetId([
            'group_id' => $this->group->id, 'hotel_id' => $this->hotel->id, 'number' => 'R1', 'booking_ref' => 'B1', 'guest_id' => $guest->id,
            'room_type_id' => $type->id, 'rate_plan_id' => $plan->id, 'arrival' => now()->toDateString(), 'departure' => now()->addDay()->toDateString(),
            'total_amount' => 50_000, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $folio = DB::table('folios')->insertGetId(['group_id' => $this->group->id, 'hotel_id' => $this->hotel->id, 'number' => 'F1', 'guest_id' => $guest->id, 'reservation_id' => $res, 'name' => 'Brice', 'balance' => 50_000, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('guest_register_entries')->insert([
            'group_id' => $this->group->id, 'hotel_id' => $this->hotel->id, 'reservation_id' => $res, 'guest_id' => $guest->id,
            'room_number' => '101', 'full_name' => 'Brice Kamga', 'id_number' => 'CNI123', 'arrival' => now()->toDateString(),
            'departure' => now()->addDay()->toDateString(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $company = Company::create(['name' => 'ACME']);
        $contact = CompanyContact::create(['company_id' => $company->id, 'guest_id' => $guest->id, 'name' => 'Brice Kamga', 'email' => 'brice@test.cm']);

        // Needs guests.manage to raise.
        $housekeeper = $this->makeUser($this->group, 'room-attendant', allHotels: true);
        $this->actAs($housekeeper, $this->hotel)->postJson("/api/v1/guests/{$guest->id}/privacy-requests", ['kind' => 'anonymise', 'reason' => 'x'])->assertForbidden();

        $clerk = $this->makeUser($this->group, 'receptionist', allHotels: true);
        $this->actAs($clerk, $this->hotel);
        $req = $this->postJson("/api/v1/guests/{$guest->id}/privacy-requests", ['kind' => 'delete', 'reason' => 'Guest emailed an erasure request'])
            ->assertCreated()->assertJsonPath('status', 'pending')->json();
        $this->postJson("/api/v1/guests/{$guest->id}/privacy-requests", ['kind' => 'anonymise', 'reason' => 'again'])->assertStatus(422);
        // The receptionist cannot approve.
        $this->postJson("/api/v1/guests/privacy-requests/{$req['id']}/approve")->assertForbidden();

        // A front-office manager who raised a request cannot approve their own.
        $fom = $this->makeUser($this->group, 'front-office-manager', allHotels: true);
        $this->actAs($fom, $this->hotel);
        $this->getJson('/api/v1/guests/privacy-requests?status=pending')->assertOk()->assertJsonPath('total', 1);
        $this->postJson("/api/v1/guests/privacy-requests/{$req['id']}/approve", ['note' => 'Identity checked'])->assertOk()->assertJsonPath('status', 'approved');
        $this->postJson("/api/v1/guests/privacy-requests/{$req['id']}/approve")->assertStatus(422);

        $g = DB::table('guests')->find($guest->id);
        $this->assertSame('Anonymised', $g->first_name);
        $this->assertNull($g->email);
        $this->assertNull($g->phone);
        $this->assertNull($g->id_number);
        $this->assertNull($g->notes);
        $this->assertNotNull($g->deleted_at, 'delete kind soft-deletes the profile');
        $reg = DB::table('guest_register_entries')->where('guest_id', $guest->id)->first();
        $this->assertSame('Anonymised', $reg->full_name);
        $this->assertNull($reg->id_number);
        $c = DB::table('company_contacts')->find($contact->id);
        $this->assertSame('Anonymised', $c->name);
        $this->assertNull($c->email);

        // Financial rows are kept untouched.
        $this->assertSame(50_000, (int) DB::table('folios')->find($folio)->balance);
        $this->assertSame($guest->id, (int) DB::table('folios')->find($folio)->guest_id);
        $this->assertSame(50_000, (int) DB::table('reservations')->find($res)->total_amount);

        // Audit entry written, without personal data in any earlier log row of the guest.
        $this->assertTrue(DB::table('activity_log')->where('log_name', 'data-protection')->where('description', 'guest personal data anonymised')->where('causer_id', $fom->id)->exists());
        $this->assertFalse(DB::table('activity_log')->where('properties', 'like', '%brice@test.cm%')->exists());
    }

    public function test_requester_cannot_approve_and_reject_needs_note(): void
    {
        $guest = Guest::create(['first_name' => 'A', 'last_name' => 'B', 'email' => 'a@b.cm']);
        $req = $this->postJson("/api/v1/guests/{$guest->id}/privacy-requests", ['kind' => 'anonymise', 'reason' => 'Asked at desk'])->assertCreated()->json();
        $this->postJson("/api/v1/guests/privacy-requests/{$req['id']}/approve")->assertStatus(422)
            ->assertJsonPath('type', 'https://docs.opeshotelsoft.test/problems/four-eyes');

        $other = $this->makeUser($this->group, 'general-manager', allHotels: true);
        $this->actAs($other, $this->hotel);
        $this->postJson("/api/v1/guests/privacy-requests/{$req['id']}/reject")->assertStatus(422);
        $this->postJson("/api/v1/guests/privacy-requests/{$req['id']}/reject", ['note' => 'Active stay; legal hold'])->assertOk()->assertJsonPath('status', 'rejected');
        $this->assertSame('a@b.cm', Guest::find($guest->id)->email);
    }
}
