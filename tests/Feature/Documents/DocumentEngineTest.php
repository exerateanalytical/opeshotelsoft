<?php

namespace Tests\Feature\Documents;

use App\Modules\Documents\Application\DocumentRenderer;
use App\Modules\Documents\Application\IssuedDocuments;
use App\Modules\Documents\Application\Letterhead;
use App\Modules\Documents\Application\Watermark;
use App\Modules\Documents\Domain\IssuedDocument;
use App\Modules\Property\Domain\Room;
use App\Modules\Reservations\Application\ReservationService;
use App\Platform\Support\QrCode;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** D-A: issued documents, verify page, QR, watermark, letterheads, sizes. */
class DocumentEngineTest extends TestCase
{
    private function render(array $options = [], string $type = 'letter'): \App\Modules\Documents\Application\RenderedDocument
    {
        return app(DocumentRenderer::class)->render($type, ['docTitle' => 'T', 'docHtml' => '<p>Hello</p>'], $options + [
            'view' => 'documents::template', 'hotel' => $this->hotel, 'number' => 'L-1', 'source_id' => 11, 'amount' => 125000, 'currency' => 'XAF',
        ]);
    }

    private function invoiceId(): int
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

        return $this->postJson("/api/v1/folios/{$folio['id']}/invoice")->assertCreated()->json('id');
    }

    public function test_issuing_the_same_source_twice_returns_one_record_and_counts_copies(): void
    {
        $a = $this->render();
        $b = $this->render();
        $c = $this->render();
        $this->assertSame(0, $a->copy);
        $this->assertSame(1, $b->copy);
        $this->assertSame(2, $c->copy);
        $this->assertSame($a->record->id, $c->record->id);
        $this->assertSame(1, IssuedDocument::count());
        $rec = IssuedDocument::first();
        $this->assertSame(2, $rec->copy_count);
        $this->assertSame([1, 2], $rec->reprints->pluck('copy_number')->all());
        $this->assertSame($this->user->id, $rec->reprints->first()->printed_by);
        // Original issue time is kept on reprints.
        $this->assertSame($a->engine['stamp_utc'], $c->engine['stamp_utc']);
        $this->assertSame(0, $a->engine['copy']);
        $this->assertSame(2, $c->engine['copy']);
    }

    public function test_qr_holds_the_verify_url_and_the_pdf_is_valid(): void
    {
        $d = $this->render();
        $rec = $d->record;
        $this->assertStringStartsWith('%PDF', $d->pdf);
        $this->assertMatchesRegularExpression('/^[0-9a-z]+-[0-9a-f]{10}$/', $rec->code);
        $this->assertSame(url('/verify/'.$rec->code), $d->engine['verify_url']);
        $expected = 'data:image/svg+xml;base64,'.base64_encode(QrCode::svg(url('/verify/'.$rec->code).'?h='.substr($rec->content_hash, 0, 12), 4, 2));
        $this->assertSame($expected, $d->engine['qr']);
        $this->assertSame($rec->code, IssuedDocuments::codeFor($rec));
    }

    public function test_public_verify_json_page_and_no_sensitive_data(): void
    {
        $rec = $this->render()->record;
        $this->app['auth']->forgetGuards();
        $json = $this->getJson('/api/v1/verify/'.$rec->code)->assertOk()->json();
        $this->assertEqualsCanonicalizing(
            ['type', 'type_label', 'number', 'hotel', 'issued_at_utc', 'issued_at_local', 'timezone', 'amount', 'currency', 'status', 'status_at', 'replaced_by', 'hash_match'],
            array_keys($json),
        );
        $this->assertSame('valid', $json['status']);
        $this->assertSame('L-1', $json['number']);
        $this->assertSame(125000, $json['amount']);
        $this->assertSame($this->hotel->name, $json['hotel']);
        $this->assertNull($json['hash_match']);
        $this->assertStringNotContainsString($rec->content_hash, json_encode($json));
        $this->get('/verify/'.$rec->code)->assertOk();
        $this->get('/verify/'.$rec->code.'?lang=en')->assertOk();
        // A forged code (right id, wrong MAC) and garbage are unknown.
        $this->getJson('/api/v1/verify/'.explode('-', $rec->code)[0].'-0000000000')->assertNotFound();
        $this->getJson('/api/v1/verify/zz-zz')->assertNotFound();
        $this->get('/verify/'.explode('-', $rec->code)[0].'-0000000000')->assertNotFound();
    }

    public function test_personal_amounts_are_never_public(): void
    {
        $rec = $this->render([], 'payslip')->record;
        $this->assertSame(125000, (int) $rec->amount);
        $this->getJson('/api/v1/verify/'.$rec->code)->assertOk()->assertJsonPath('amount', null);
    }

    public function test_tampered_content_hash_is_detected(): void
    {
        $rec = $this->render()->record;
        $this->getJson('/api/v1/verify/'.$rec->code.'?h='.substr($rec->content_hash, 0, 16))->assertOk()->assertJsonPath('hash_match', true);
        $this->getJson('/api/v1/verify/'.$rec->code.'?h='.strtoupper($rec->content_hash))->assertOk()->assertJsonPath('hash_match', true);
        $this->getJson('/api/v1/verify/'.$rec->code.'?h=deadbeefdeadbeef')->assertOk()->assertJsonPath('hash_match', false);
        // The hash follows the data: different content, different fingerprint.
        $this->assertNotSame(IssuedDocuments::hash(['a' => 1]), IssuedDocuments::hash(['a' => 2]));
        $this->assertSame(IssuedDocuments::hash(['b' => 2, 'a' => 1]), IssuedDocuments::hash(['a' => 1, 'b' => 2]));
    }

    public function test_voiding_and_replacing_flip_the_verify_page(): void
    {
        $svc = app(IssuedDocuments::class);
        $rec = $this->render()->record;
        $this->assertSame(1, $svc->voidSource('letter', 'letter', 11, 'wrong amount'));
        $this->getJson('/api/v1/verify/'.$rec->code)->assertOk()->assertJsonPath('status', 'voided');
        $this->assertSame(0, $svc->voidSource('letter', 'letter', 11)); // already flipped

        $old = $this->render(['source_id' => 12, 'number' => 'L-2'])->record;
        $new = $this->render(['source_id' => 13, 'number' => 'L-3'])->record;
        $svc->replaceSource('letter', 'letter', 12, $new);
        $this->getJson('/api/v1/verify/'.$old->code)->assertOk()->assertJsonPath('status', 'replaced')->assertJsonPath('replaced_by.number', 'L-3');
        // Voided documents are never deleted and still print (with a copy mark).
        $this->assertSame(1, $this->render()->copy);
    }

    public function test_invoice_pdf_is_issued_and_reprint_counts(): void
    {
        $id = $this->invoiceId();
        $this->get("/api/v1/documents/invoices/$id")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $rec = IssuedDocument::where('type', 'invoice')->firstOrFail();
        $this->assertSame(0, $rec->copy_count);
        $this->get("/api/v1/documents/invoices/$id")->assertOk();
        $this->assertSame(1, $rec->fresh()->copy_count);
        $this->assertSame('fr', $rec->locale);
        // Even asking for English keeps the legal invoice French.
        $this->get("/api/v1/documents/invoices/$id?lang=en")->assertOk();
        $this->assertSame('fr', $rec->fresh()->locale);
        $this->assertGreaterThan(0, $rec->amount);
    }

    public function test_watermark_on_off_modes_and_scope(): void
    {
        Storage::fake('local');
        $group = $this->group;
        $this->assertNull(Watermark::forRender($this->hotel, true, 0)); // off by default
        $plain = $this->render(['source_id' => 21, 'number' => 'W-1'])->pdf;

        Watermark::save($group, ['mode' => 'text', 'text' => 'CONFIDENTIAL', 'opacity' => 20, 'position' => 'diagonal', 'apply' => 'all']);
        $wm = Watermark::forRender($this->hotel, false, 0);
        $this->assertSame('CONFIDENTIAL', $wm['text']);
        $this->assertSame('diagonal', $wm['position']);
        $marked = $this->render(['source_id' => 22, 'number' => 'W-2']);
        $this->assertSame($wm, $marked->engine['watermark']);
        $this->assertNotSame(strlen($plain), strlen($marked->pdf));

        // Restricted to drafts and copies: not on an original, yes on a copy and on a draft.
        Watermark::save($group, ['apply' => 'drafts']);
        $this->assertNull($this->render(['source_id' => 23, 'number' => 'W-3'])->engine['watermark']);
        $this->assertNotNull($this->render(['source_id' => 23, 'number' => 'W-3'])->engine['watermark']); // Duplicata 1
        $this->assertNotNull($this->render(['source_id' => 24, 'number' => 'W-4', 'draft' => true])->engine['watermark']);

        foreach (['center', 'tile'] as $pos) {
            Watermark::save($group, ['apply' => 'all', 'position' => $pos]);
            $this->assertStringStartsWith('%PDF', $this->render(['source_id' => 25, 'number' => 'W-5'])->pdf);
        }
        Watermark::save($group, ['mode' => 'none']);
        $this->assertNull(Watermark::forRender($this->hotel, true, 3));
    }

    public function test_watermark_api_image_upload_rules_and_wizard_step(): void
    {
        Storage::fake('local');
        $png = UploadedFile::fake()->image('wm.png', 40, 40);
        $this->post('/api/v1/documents/watermark/image', ['image' => $png], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('mode', 'image');
        $big = UploadedFile::fake()->image('big.png', 200, 200)->size(1500);
        $this->post('/api/v1/documents/watermark/image', ['image' => $big], ['Accept' => 'application/json'])->assertStatus(422);
        $svg = UploadedFile::fake()->create('x.svg', 4, 'image/svg+xml');
        $this->post('/api/v1/documents/watermark/image', ['image' => $svg], ['Accept' => 'application/json'])->assertStatus(422);
        $this->putJson('/api/v1/documents/watermark', ['mode' => 'image', 'text' => '', 'opacity' => 15, 'position' => 'tile', 'apply' => 'all'])->assertOk()->assertJsonPath('position', 'tile');
        $this->assertNotNull(Watermark::forRender($this->hotel, false, 0)['image']);
        $this->putJson('/api/v1/documents/watermark', ['mode' => 'text', 'text' => '', 'opacity' => 15, 'position' => 'tile', 'apply' => 'all'])->assertStatus(422);
        $this->putJson('/api/v1/documents/watermark', ['mode' => 'text', 'text' => 'X', 'opacity' => 99, 'position' => 'tile', 'apply' => 'all'])->assertStatus(422);
        $this->postJson('/api/v1/documents/watermark/image/remove')->assertOk()->assertJsonPath('image', null);

        $state = $this->getJson('/api/v1/onboarding/state')->assertOk()->json();
        $this->assertContains('watermark', collect($state['steps'])->pluck('key')->all());
        $this->putJson('/api/v1/onboarding/state', ['complete' => ['watermark'], 'current_step' => 'golive'])->assertOk();
    }

    public function test_seven_letterheads_render_and_are_selectable_per_hotel(): void
    {
        $this->assertCount(7, Letterhead::DESIGNS);
        $this->hotel->update(['legal_name' => 'Akwa Hotel SA', 'tax_id' => 'M0123456789', 'trade_register' => 'RC/DLA/2020/B/1', 'address' => 'Bd de la Liberte', 'city' => 'Douala',
            'phone' => '+237600000000', 'bank_details' => 'AFRILAND 12345', 'document_footer' => 'Merci', 'brand_color' => '#0f766e', 'accent_color' => '#b45309']);
        foreach (Letterhead::DESIGNS as $i => $design) {
            $this->putJson('/api/v1/documents/branding', ['letterhead_design' => $design])->assertOk()->assertJsonPath('letterhead_design', $design);
            $this->assertSame($design, Letterhead::for($this->hotel->fresh())['design']);
            $d = $this->render(['source_id' => 100 + $i, 'number' => "LH-$i", 'hotel' => $this->hotel->fresh()]);
            $this->assertSame($design, $d->engine['design']);
            $this->assertStringStartsWith('%PDF', $d->pdf);
            $html = $this->getJson('/api/v1/documents/branding/preview?design='.$design.'&brand_color='.urlencode('#112233'))->assertOk()->json('html');
            $this->assertStringContainsString('data-letterhead="'.$design.'"', $html);
            $this->assertStringContainsString('Akwa Hotel SA', $html);
            $this->assertStringContainsString('#112233', $html);
            $this->assertStringContainsString('M0123456789', $html);
            $this->assertStringContainsString('AFRILAND 12345', $html);
        }
        $this->putJson('/api/v1/documents/branding', ['letterhead_design' => 'neon'])->assertStatus(422);
        $this->get('/api/v1/documents/branding/sample?design=resort')->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertSame(1, IssuedDocument::where('type', 'letter')->where('number', 'like', 'LH-%')->count() > 0 ? 1 : 0);
    }

    public function test_page_sizes(): void
    {
        foreach (array_keys(DocumentRenderer::SIZES) as $i => $size) {
            $d = $this->render(['size' => $size, 'source_id' => 200 + $i, 'number' => "SZ-$i"]);
            $this->assertStringStartsWith('%PDF', $d->pdf, $size);
            $this->assertSame($size, $d->engine['size']);
        }
    }

    public function test_reprint_log_needs_its_permission_and_is_tenant_scoped(): void
    {
        $rec = $this->render()->record;
        $this->render();
        $this->getJson('/api/v1/documents/issued')->assertOk()->assertJsonPath('data.0.copy_count', 1);
        $this->getJson("/api/v1/documents/issued/{$rec->id}")->assertOk()->assertJsonPath('reprints.0.copy_number', 1);

        $clerk = $this->makeUser($this->group, 'room-attendant', allHotels: true);
        $this->actAs($clerk, $this->hotel);
        $this->getJson('/api/v1/documents/issued')->assertForbidden();

        [$g2, $h2] = $this->makeHotel('Other Group', 'OTH');
        $other = $this->makeUser($g2, 'general-manager', allHotels: true);
        $this->actAs($other, $h2);
        $this->getJson("/api/v1/documents/issued/{$rec->id}")->assertNotFound();
    }

    public function test_every_existing_pdf_kind_goes_through_the_engine(): void
    {
        $id = $this->invoiceId();
        $folioId = \App\Modules\FrontOffice\Domain\Folio::first()->id;
        $this->get("/api/v1/documents/folios/$folioId")->assertOk();
        $this->get("/api/v1/documents/invoices/$id")->assertOk();
        $types = IssuedDocument::pluck('type')->all();
        $this->assertContains('folio', $types);
        $this->assertContains('invoice', $types);
    }
}
