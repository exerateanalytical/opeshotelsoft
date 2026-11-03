<?php

namespace Tests\Feature\Documents;

use App\Modules\Documents\Application\NumberToWords;
use App\Modules\Documents\Domain\ScheduledReport;
use App\Modules\HR\Domain\Department;
use App\Modules\Property\Domain\Room;
use App\Modules\Reservations\Application\ReservationService;
use App\Modules\Reservations\Domain\Reservation;
use App\Modules\Stock\Domain\StockItem;
use App\Modules\Stock\Domain\Store;
use App\Modules\Stock\Domain\Supplier;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** W05: PDFs, exports and scheduled reports. */
class DocumentsTest extends TestCase
{
    private const PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    private function checkedIn(): Reservation
    {
        [$type, $plan, $guest] = $this->seedInventory();
        $guest->update(['id_type' => 'passport', 'id_number' => 'CM123456', 'nationality' => 'CM']);
        $r = app(ReservationService::class)->create([
            'guest_id' => $guest->id, 'room_type_id' => $type->id, 'rate_plan_id' => $plan->id,
            'arrival' => now()->toDateString(), 'departure' => now()->addDay()->toDateString(),
        ]);
        $room = Room::where('room_type_id', $type->id)->first();
        $this->postJson("/api/v1/reservations/{$r->id}/check-in", ['room_id' => $room->id])->assertOk();

        return $r->fresh();
    }

    private function assertPdf($res): void
    {
        $res->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $res->getContent());
        $this->assertGreaterThan(1000, strlen($res->getContent()));
    }

    public function test_invoice_folio_and_registration_card_pdfs_for_authorised_users_only(): void
    {
        Storage::fake('local');
        $this->hotel->update(['legal_name' => 'Akwa Hotel SA', 'tax_id' => 'M0123456789', 'trade_register' => 'RC/DLA/2020/B/123']);
        $r = $this->checkedIn();
        $folio = $this->getJson("/api/v1/reservations/{$r->id}/folio")->assertOk()->json();
        $this->postJson("/api/v1/folios/{$folio['id']}/charges", ['charge_code' => 'MISC', 'amount' => 25_000])->assertSuccessful();
        $inv = $this->postJson("/api/v1/folios/{$folio['id']}/invoice")->assertCreated()->json();
        $this->postJson("/api/v1/reservations/{$r->id}/registration-card", ['signature' => self::PNG])->assertOk();

        $this->assertPdf($this->get("/api/v1/documents/invoices/{$inv['id']}"));
        $this->assertPdf($this->get("/api/v1/documents/invoices/{$inv['id']}?lang=en"));
        $this->assertPdf($this->get("/api/v1/documents/folios/{$folio['id']}"));
        $this->assertPdf($this->get("/api/v1/documents/registration-cards/{$r->id}?lang=en"));

        $attendant = $this->makeUser($this->group, 'room-attendant', allHotels: true);
        $this->actAs($attendant, $this->hotel);
        $this->get("/api/v1/documents/invoices/{$inv['id']}")->assertForbidden();
        $this->get("/api/v1/documents/folios/{$folio['id']}")->assertForbidden();
        $this->get("/api/v1/documents/registration-cards/{$r->id}")->assertForbidden();
    }

    public function test_payslip_pdf_in_employee_language_and_forbidden_to_others(): void
    {
        $dept = Department::create(['code' => 'FO', 'name' => 'Réception', 'cost_center' => 'rooms']);
        $this->postJson('/api/v1/hr/employees', [
            'first_name' => 'Aïcha', 'last_name' => 'Bello', 'hire_date' => now()->startOfMonth()->subYear()->toDateString(),
            'department_id' => $dept->id, 'payment_method' => 'mobile_money', 'momo_number' => '677000000',
            'contract' => ['kind' => 'CDI', 'start_date' => now()->startOfMonth()->subYear()->toDateString(), 'base_salary' => 300_000],
        ])->assertCreated();
        $run = $this->postJson('/api/v1/payroll/runs', ['period' => now()->format('Y-m')])->assertOk()->json();
        $slip = $run['payslips'][0]['id'];

        $this->assertPdf($this->get("/api/v1/documents/payslips/$slip"));
        $this->assertPdf($this->get("/api/v1/documents/payslips/$slip?lang=en"));

        $this->actAs($this->makeUser($this->group, 'receptionist', allHotels: true), $this->hotel);
        $this->get("/api/v1/documents/payslips/$slip")->assertForbidden();
    }

    public function test_purchase_order_pdf(): void
    {
        $store = Store::create(['code' => 'MAIN', 'name' => 'Magasin', 'kind' => 'main', 'department' => 'fnb']);
        $item = StockItem::create(['code' => 'RIZ', 'name' => 'Riz', 'category' => 'food', 'unit' => 'kg', 'inventory_account' => '311200', 'cost_account' => '603120', 'vat_rate' => 0]);
        $s = Supplier::create(['code' => 'S1', 'name' => 'Grossiste A']);
        $po = $this->postJson('/api/v1/purchasing/orders', ['supplier_id' => $s->id, 'store_id' => $store->id, 'lines' => [['stock_item_id' => $item->id, 'quantity' => 10, 'unit_price' => 800]]])->assertCreated()->json();

        $this->assertPdf($this->get("/api/v1/documents/purchase-orders/{$po['id']}"));
        $this->actAs($this->makeUser($this->group, 'room-attendant', allHotels: true), $this->hotel);
        $this->get("/api/v1/documents/purchase-orders/{$po['id']}")->assertForbidden();
    }

    public function test_exports_return_the_right_content_type_and_respect_report_permissions(): void
    {
        $this->seedInventory();
        $tb = $this->get('/api/v1/exports/trial-balance?format=xlsx')->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->assertStringStartsWith('PK', $tb->getContent());

        $csv = $this->get('/api/v1/exports/stock-balances?format=csv')->assertOk();
        $this->assertStringContainsString('text/csv', $csv->headers->get('Content-Type'));
        $this->assertStringContainsString('store;code;name', $csv->getContent());

        $this->get('/api/v1/exports/forecast?format=csv&days=7')->assertOk();
        $this->get('/api/v1/exports/income-statement?format=pdf&from='.now()->startOfMonth()->toDateString().'&to='.now()->toDateString())
            ->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->get('/api/v1/exports/police-register?format=xlsx&from='.now()->toDateString().'&to='.now()->toDateString())->assertOk();
        // The report's own validation is passed through.
        $this->getJson('/api/v1/exports/income-statement?format=csv')->assertStatus(422);
        $this->getJson('/api/v1/exports/nope?format=csv')->assertStatus(422);

        $this->actAs($this->makeUser($this->group, 'room-attendant', allHotels: true), $this->hotel);
        $this->get('/api/v1/exports/trial-balance?format=csv')->assertForbidden();
    }

    public function test_scheduled_report_crud_and_run_emails_the_export(): void
    {
        Event::fake([MessageSent::class]);
        $s = $this->postJson('/api/v1/scheduled-reports', [
            'name' => 'Daily trial balance', 'report' => 'trial-balance', 'format' => 'xlsx',
            'recipients' => ['dg@akwa.cm'], 'frequency' => '0 7 * * *',
        ])->assertCreated()->json();
        $this->assertNotNull($s['next_run_at']);
        $this->postJson('/api/v1/scheduled-reports', ['name' => 'x', 'report' => 'trial-balance', 'format' => 'csv', 'recipients' => ['a@b.cm'], 'frequency' => 'every day'])->assertStatus(422);
        $this->putJson("/api/v1/scheduled-reports/{$s['id']}", ['format' => 'csv'])->assertOk()->assertJsonPath('format', 'csv');
        $this->getJson('/api/v1/scheduled-reports')->assertOk()->assertJsonCount(1);

        // Make it due and run the command.
        ScheduledReport::whereKey($s['id'])->update(['next_run_at' => now()->subMinute()]);
        $this->artisan('reports:send-scheduled')->assertSuccessful();
        $fresh = ScheduledReport::find($s['id']);
        $this->assertSame('sent', $fresh->last_status, (string) $fresh->last_error);
        $this->assertTrue($fresh->next_run_at->isFuture());
        Event::assertDispatched(MessageSent::class);

        $this->postJson("/api/v1/scheduled-reports/{$s['id']}/run")->assertOk()->assertJsonPath('last_status', 'sent');

        $this->actAs($this->makeUser($this->group, 'receptionist', allHotels: true), $this->hotel);
        $this->getJson('/api/v1/scheduled-reports')->assertForbidden();
    }

    public function test_amounts_in_words(): void
    {
        $this->assertSame('quatre-vingt-un mille deux cent cinquante', NumberToWords::convert(81_250));
        $this->assertSame('deux cents', NumberToWords::convert(200));
        $this->assertSame('soixante et onze', NumberToWords::convert(71));
        $this->assertSame('one million two hundred thousand', NumberToWords::convert(1_200_000, 'en'));
    }

    public function test_scheduled_reports_page_needs_permission(): void
    {
        $this->actingAs($this->user)->get('/documents/scheduled-reports')->assertOk();
        $this->actingAs($this->makeUser($this->group, 'receptionist', allHotels: true))->get('/documents/scheduled-reports')->assertForbidden();
    }
}
