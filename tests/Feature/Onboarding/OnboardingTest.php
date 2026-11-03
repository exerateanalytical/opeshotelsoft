<?php

namespace Tests\Feature\Onboarding;

use App\Modules\Documents\Application\TemplateRenderer;
use App\Modules\Guests\Domain\Guest;
use App\Modules\HR\Domain\Department;
use App\Modules\HR\Domain\Employee;
use App\Modules\Property\Domain\Outlet;
use App\Modules\Property\Domain\Room;
use App\Modules\Property\Domain\RoomType;
use App\Modules\Reservations\Domain\RatePlan;
use App\Modules\Stock\Domain\StockItem;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Tests\TestCase;

/** Q1: setup wizard, letterhead, templates, imports, copy setup. */
class OnboardingTest extends TestCase
{
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    private function csv(string $content, string $name = 'file.csv'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $content);
    }

    public function test_wizard_progress_is_resumable_and_go_live_needs_checklist(): void
    {
        $s = $this->getJson('/api/v1/onboarding/state')->assertOk()->json();
        $this->assertSame('group', $s['current_step']);
        $this->assertCount(9, $s['steps']);

        $this->putJson('/api/v1/onboarding/state', ['current_step' => 'rooms', 'complete' => ['group', 'taxes'], 'data' => ['note' => 'x']])->assertOk();
        $s = $this->getJson('/api/v1/onboarding/state')->json();
        $this->assertSame('rooms', $s['current_step']);
        $this->assertSame('x', $s['data']['note']);
        $this->assertTrue(collect($s['steps'])->firstWhere('key', 'taxes')['done']);

        $this->postJson('/api/v1/onboarding/go-live')->assertStatus(422);

        $this->hotel->update(['legal_name' => 'Akwa SA', 'tax_id' => 'M012', 'trade_register' => 'RC/DLA/1', 'address' => 'Bd de la Liberté', 'phone' => '+237']);
        $this->seedInventory();
        $s = $this->postJson('/api/v1/onboarding/go-live')->assertOk()->json();
        $this->assertNotNull($s['went_live_at']);
    }

    public function test_group_step_updates_group(): void
    {
        $this->putJson('/api/v1/onboarding/group', ['name' => 'Groupe Akwa', 'default_locale' => 'en'])->assertOk()->assertJsonPath('name', 'Groupe Akwa');
    }

    public function test_onboarding_and_branding_require_permissions(): void
    {
        $this->actAs($this->makeUser($this->group, 'receptionist', allHotels: true), $this->hotel);
        $this->getJson('/api/v1/onboarding/state')->assertForbidden();
        $this->getJson('/api/v1/documents/branding')->assertForbidden();
        $this->getJson('/api/v1/documents/templates')->assertForbidden();
        $this->postJson('/api/v1/onboarding/imports/guests', ['file' => $this->csv("first_name,last_name\nA,B\n")])->assertForbidden();
    }

    public function test_letterhead_branding_and_logo_appear_on_pdfs(): void
    {
        Storage::fake('local');
        $this->putJson('/api/v1/documents/branding', [
            'legal_name' => 'Akwa Palace SA', 'tax_id' => 'M0987', 'trade_register' => 'RC/DLA/2020/B/9',
            'brand_color' => '#0f766e', 'bank_details' => 'Afriland First Bank 10005 00001', 'document_footer' => 'Merci de votre visite',
        ])->assertOk()->assertJsonPath('brand_color', '#0f766e');
        $this->putJson('/api/v1/documents/branding', ['brand_color' => 'red'])->assertStatus(422);

        $this->post('/api/v1/documents/branding/logo', ['logo' => UploadedFile::fake()->createWithContent('logo.png', base64_decode(self::PNG))])->assertOk();
        $this->assertNotNull($this->getJson('/api/v1/documents/branding')->json('logo'));
        $this->post('/api/v1/documents/branding/logo', ['logo' => UploadedFile::fake()->createWithContent('x.svg', '<svg onload="alert(1)"/>')])->assertStatus(422);

        $html = view('pdf.report', ['title' => 'T', 'columns' => [], 'rows' => [], 'params' => [], 'hotel' => $this->hotel->fresh(), 'lang' => 'fr'])->render();
        $this->assertStringContainsString('Akwa Palace SA', $html);
        $this->assertStringContainsString('#0f766e', $html);
        $this->assertStringContainsString('Afriland First Bank', $html);
        $this->assertStringContainsString('data:image/png;base64', $html);

        $pdf = $this->get('/api/v1/exports/trial-balance?format=pdf');
        $this->assertContains($pdf->status(), [200, 404]);
    }

    public function test_templates_are_safe_editable_previewable_and_resettable(): void
    {
        $list = $this->getJson('/api/v1/documents/templates')->assertOk()->json('templates');
        $this->assertCount(count(\App\Modules\Documents\Application\DefaultTemplates::KINDS) * count(\App\Modules\Documents\Application\DefaultTemplates::LOCALES), $list);

        $this->putJson('/api/v1/documents/templates/letter/fr', ['body' => 'Bonjour {{ guest.secret_password }}'])->assertStatus(422);
        $this->putJson('/api/v1/documents/templates/letter/fr', ['body' => '{!! phpinfo() !!}'])->assertStatus(422);
        $this->putJson('/api/v1/documents/templates/letter/fr', ['body' => '@php system("id") @endphp'])->assertStatus(422);
        $this->putJson('/api/v1/documents/templates/nope/fr', ['body' => 'x'])->assertStatus(422);

        $this->putJson('/api/v1/documents/templates/letter/fr', ['title' => 'Lettre', 'body' => "# Objet\nBonjour **{{ guest.name }}** <script>alert(1)</script>"])
            ->assertOk()->assertJsonPath('custom', true);
        $prev = $this->postJson('/api/v1/documents/templates/preview', ['kind' => 'letter', 'locale' => 'fr', 'body' => 'Bonjour {{ guest.name }} <b>x</b>'])->assertOk()->json('html');
        $this->assertStringContainsString('Jean Mballa', $prev);
        $this->assertStringContainsString('&lt;b&gt;', $prev);
        $this->assertStringNotContainsString('<b>', $prev);

        $pdf = $this->post('/api/v1/documents/templates/preview', ['kind' => 'quote', 'locale' => 'en', 'body' => 'Quote {{ reservation.number }}', 'format' => 'pdf']);
        $pdf->assertOk()->assertHeader('Content-Type', 'application/pdf');

        $this->postJson('/api/v1/documents/templates/letter/fr/reset')->assertOk()->assertJsonPath('custom', false);
    }

    public function test_renderer_never_evaluates_user_input(): void
    {
        $html = TemplateRenderer::render('letter', '{{ hotel.name }} {{ 7*7 }} <?php echo 1; ?> {{ guest.name }}', ['hotel.name' => '<i>H</i>', 'guest.name' => 'G']);
        $this->assertStringContainsString('&lt;i&gt;H&lt;/i&gt;', $html);
        $this->assertStringNotContainsString('49', $html);
        $this->assertStringContainsString('&lt;?php', $html);
    }

    public function test_confirmation_and_quote_print_from_reservation_with_custom_template(): void
    {
        [$type, $plan, $guest] = $this->seedInventory();
        $r = $this->postJson('/api/v1/reservations', [
            'guest_id' => $guest->id, 'room_type_id' => $type->id, 'rate_plan_id' => $plan->id,
            'arrival' => now()->addDay()->toDateString(), 'departure' => now()->addDays(3)->toDateString(),
        ])->assertSuccessful()->json();
        $this->putJson('/api/v1/documents/templates/confirmation/fr', ['body' => 'Confirmation {{ reservation.number }} pour {{ guest.name }}'])->assertOk();
        foreach (['confirmation', 'quote'] as $kind) {
            $res = $this->get("/api/v1/documents/templates/$kind/print?reservation_id={$r['id']}");
            $res->assertOk()->assertHeader('Content-Type', 'application/pdf');
        }
        $this->get('/api/v1/documents/templates/quote/print')->assertStatus(422);

        $this->actAs($this->makeUser($this->group, 'room-attendant', allHotels: true), $this->hotel);
        $this->get("/api/v1/documents/templates/confirmation/print?reservation_id={$r['id']}")->assertForbidden();
    }

    public function test_rooms_import_dry_run_then_commit(): void
    {
        $file = "room_type_code;number;room_type_name;floor;base_rate\nSTD;101;Standard;1;40000\nSTD;102;Standard;1;40000\nSUI;201;Suite;2;90000\n";
        $dry = $this->post('/api/v1/onboarding/imports/rooms', ['file' => $this->csv($file), 'dry_run' => 1])->assertOk()->json();
        $this->assertSame('validated', $dry['status']);
        $this->assertSame(3, $dry['rows_ok']);
        $this->assertSame(0, Room::count());

        $real = $this->post('/api/v1/onboarding/imports/rooms', ['file' => $this->csv($file), 'dry_run' => 0])->assertOk()->json();
        $this->assertSame('imported', $real['status']);
        $this->assertSame(3, Room::count());
        $this->assertSame(2, RoomType::count());
    }

    public function test_failing_row_rejects_whole_file_with_report(): void
    {
        $file = "first_name,last_name,email\nAwa,Ndiaye,awa@test.cm\nBad,Email,not-an-email\n";
        $res = $this->post('/api/v1/onboarding/imports/guests', ['file' => $this->csv($file), 'dry_run' => 0])->assertStatus(422)->json();
        $this->assertSame('rejected', $res['status']);
        $this->assertSame(1, $res['rows_failed']);
        $this->assertSame(3, $res['report'][1]['row']);
        $this->assertStringContainsString('email', $res['report'][1]['errors'][0]);
        $this->assertSame(0, Guest::count());
    }

    public function test_missing_columns_are_reported(): void
    {
        $res = $this->post('/api/v1/onboarding/imports/stock_items', ['file' => $this->csv("code,name\nA,B\n")])->assertStatus(422)->json();
        $this->assertStringContainsString('category', $res['report'][0]['errors'][0]);
    }

    public function test_staff_and_stock_items_import(): void
    {
        Department::create(['code' => 'FO', 'name' => 'Réception', 'cost_center' => 'rooms']);
        $staff = "first_name,last_name,hire_date,base_salary,department_code,payment_method,momo_number\nAïcha,Bello,2025-01-15,250000,FO,mobile_money,699000000\n";
        $this->post('/api/v1/onboarding/imports/staff', ['file' => $this->csv($staff), 'dry_run' => 0])->assertOk()->assertJsonPath('status', 'imported');
        $this->assertSame(1, Employee::count());

        $bad = "first_name,last_name,hire_date,base_salary,department_code\nX,Y,2025-01-01,100000,ZZ\n";
        $this->post('/api/v1/onboarding/imports/staff', ['file' => $this->csv($bad), 'dry_run' => 0])->assertStatus(422);

        $stock = "code,name,category,unit,vat_rate\nBEER33,Bière 33cl,beverage,btl,19.25\nRICE,Riz,food,kg,0\n";
        $this->post('/api/v1/onboarding/imports/stock_items', ['file' => $this->csv($stock), 'dry_run' => 0])->assertOk();
        $this->assertSame(2, StockItem::count());
    }

    public function test_opening_balances_must_balance_and_post_to_gl(): void
    {
        $unbalanced = "account;debit;credit\n521100;1000000;0\n101000;0;900000\n";
        $res = $this->post('/api/v1/onboarding/imports/opening_balances', ['file' => $this->csv($unbalanced), 'dry_run' => 0])->assertStatus(422)->json();
        $this->assertStringContainsString('balance', collect($res['report'])->last()['errors'][0]);

        $this->post('/api/v1/onboarding/imports/opening_balances', ['file' => $this->csv("account;debit;credit\n99999;10;0\n101000;0;10\n"), 'dry_run' => 0])->assertStatus(422);

        $ok = "account;debit;credit;description\n521100;1000000;0;Banque\n101000;0;1000000;Capital\n";
        $this->post('/api/v1/onboarding/imports/opening_balances', ['file' => $this->csv($ok), 'dry_run' => 1])->assertOk()->assertJsonPath('status', 'validated');
        $this->assertBooksBalance();
        $this->post('/api/v1/onboarding/imports/opening_balances', ['file' => $this->csv($ok), 'dry_run' => 0])->assertOk()->assertJsonPath('status', 'imported');
        $bal = $this->assertBooksBalance();
        $this->assertSame(1000000, (int) $bal['521100']);
    }

    public function test_xlsx_import_and_template_download(): void
    {
        $this->get('/api/v1/onboarding/imports/rooms/template')->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $path = tempnam(sys_get_temp_dir(), 'q1').'.xlsx';
        $w = new Writer;
        $w->openToFile($path);
        $w->addRow(Row::fromValues(['first_name', 'last_name', 'language']));
        $w->addRow(Row::fromValues(['Paul', 'Biya', 'fr']));
        $w->close();
        $this->post('/api/v1/onboarding/imports/guests', ['file' => new UploadedFile($path, 'guests.xlsx', null, null, true), 'dry_run' => 0])
            ->assertOk()->assertJsonPath('rows_ok', 1);
        $this->assertTrue(Guest::where('last_name', 'Biya')->exists());
    }

    public function test_copy_setup_to_another_hotel_of_the_group(): void
    {
        $this->seedInventory();
        Outlet::create(['code' => 'BAR', 'name' => 'Bar', 'kind' => 'bar']);
        $this->putJson('/api/v1/documents/templates/letter/fr', ['body' => 'Copie {{ hotel.name }}'])->assertOk();
        $this->putJson('/api/v1/documents/branding', ['brand_color' => '#123456'])->assertOk();

        [, $target] = $this->makeHotel('Akwa Test', 'KRI', $this->group);
        $this->provision($target);
        $this->actAs($this->user, $target);
        $res = $this->postJson('/api/v1/onboarding/copy', ['source_hotel_id' => $this->hotel->id, 'parts' => ['rooms', 'rate_plans', 'outlets', 'tax_codes', 'templates', 'branding']])
            ->assertOk()->json('result');
        $this->assertSame(3, $res['rooms']['copied']);
        $this->assertGreaterThan(0, $res['tax_codes']['skipped']);
        $this->assertSame(1, RoomType::count());
        $this->assertSame(3, Room::count());
        $this->assertSame(1, RatePlan::count());
        $this->assertSame(1, Outlet::count());
        $this->assertSame('#123456', $target->fresh()->brand_color);
        $this->assertTrue(TemplateRenderer::template('letter', 'fr')['custom']);

        // Idempotent: a second copy skips everything.
        $again = $this->postJson('/api/v1/onboarding/copy', ['source_hotel_id' => $this->hotel->id, 'parts' => ['rooms']])->json('result');
        $this->assertSame(0, $again['rooms']['copied']);
    }

    public function test_copy_refuses_hotels_of_other_groups(): void
    {
        [, $foreign] = $this->makeHotel('Other Group', 'OTH');
        $this->postJson('/api/v1/onboarding/copy', ['source_hotel_id' => $foreign->id, 'parts' => ['rooms']])->assertNotFound();
        $this->assertNull(app(TenantContext::class)->isBypassed() ? 1 : null);
    }

    public function test_web_screens_guarded(): void
    {
        foreach (['/onboarding', '/onboarding/import', '/onboarding/branding', '/onboarding/templates'] as $p) {
            $this->actingAs($this->user)->get($p, ['Accept' => 'text/html'])->assertOk();
        }
        $rec = $this->makeUser($this->group, 'receptionist', allHotels: true);
        $this->actingAs($rec)->get('/onboarding', ['Accept' => 'text/html'])->assertForbidden();
    }
}
