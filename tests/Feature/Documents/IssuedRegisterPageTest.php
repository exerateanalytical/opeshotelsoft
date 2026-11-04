<?php

namespace Tests\Feature\Documents;

use App\Modules\Documents\Domain\IssuedDocument;
use App\Modules\Property\Domain\Room;
use App\Modules\Reservations\Application\ReservationService;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** MS1: /documents/issued register page (documents.verify.log), same guard as the API. */
class IssuedRegisterPageTest extends TestCase
{
    public function test_page_access_follows_documents_verify_log(): void
    {
        foreach (['hotel-owner' => 200, 'auditor' => 200, 'receptionist' => 403, 'room-attendant' => 403] as $role => $status) {
            $u = $this->makeUser($this->group, $role, allHotels: true);
            $res = $this->actingAs($u)->get('/documents/issued');
            $res->assertStatus($status);
            if ($status === 200) {
                $res->assertInertia(fn ($p) => $p->component('Documents/Issued'));
            }
        }
    }

    public function test_issued_document_is_listed_with_its_reprint_log(): void
    {
        Storage::fake('local');
        [$type, $plan, $guest] = $this->seedInventory();
        $guest->update(['id_type' => 'passport', 'id_number' => 'CM123456', 'nationality' => 'CM']);
        $r = app(ReservationService::class)->create([
            'guest_id' => $guest->id, 'room_type_id' => $type->id, 'rate_plan_id' => $plan->id,
            'arrival' => now()->toDateString(), 'departure' => now()->addDay()->toDateString(),
        ]);
        $room = Room::where('room_type_id', $type->id)->first();
        $this->postJson("/api/v1/reservations/{$r->id}/check-in", ['room_id' => $room->id])->assertOk();
        $folio = $this->getJson("/api/v1/reservations/{$r->id}/folio")->assertOk()->json();
        $this->postJson("/api/v1/folios/{$folio['id']}/charges", ['charge_code' => 'MISC', 'amount' => 25_000])->assertSuccessful();
        $inv = $this->postJson("/api/v1/folios/{$folio['id']}/invoice")->assertCreated()->json();
        $this->get("/api/v1/documents/invoices/{$inv['id']}")->assertOk();
        $this->get("/api/v1/documents/invoices/{$inv['id']}")->assertOk();
        $doc = IssuedDocument::where('type', 'invoice')->firstOrFail();

        $this->actAs($this->makeUser($this->group, 'auditor', allHotels: true), $this->hotel);
        $this->getJson('/api/v1/documents/issued?q='.urlencode($doc->number))->assertOk()->assertJsonPath('data.0.id', $doc->id);
        $this->getJson("/api/v1/documents/issued/{$doc->id}")->assertOk()
            ->assertJsonPath('verify_path', '/verify/'.$doc->code)->assertJsonCount(1, 'reprints');

        $this->actAs($this->makeUser($this->group, 'receptionist', allHotels: true), $this->hotel);
        $this->getJson('/api/v1/documents/issued')->assertForbidden();
    }
}
