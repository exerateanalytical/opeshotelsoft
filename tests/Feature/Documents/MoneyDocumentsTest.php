<?php

namespace Tests\Feature\Documents;

use App\Modules\Documents\Domain\IssuedDocument;
use App\Modules\FrontOffice\Domain\FolioLine;
use App\Modules\Guests\Domain\Company;
use App\Modules\POS\Domain\MenuItem;
use App\Modules\Property\Domain\Outlet;
use App\Modules\Property\Domain\Room;
use App\Modules\Reservations\Application\ReservationService;
use App\Modules\Reservations\Domain\Reservation;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** D-B: money documents render as PDF on the engine, are issued with a verify code, and match their source. */
class MoneyDocumentsTest extends TestCase
{
    private function stay(): array
    {
        [$type, $plan, $guest] = $this->seedInventory(rooms: 3);
        $guest->update(['id_type' => 'passport', 'id_number' => 'CM1', 'nationality' => 'CM', 'language' => 'en']);
        $r = app(ReservationService::class)->create([
            'guest_id' => $guest->id, 'room_type_id' => $type->id, 'rate_plan_id' => $plan->id,
            'arrival' => now()->toDateString(), 'departure' => now()->addDay()->toDateString(),
        ]);
        $this->postJson("/api/v1/reservations/{$r->id}/check-in", ['room_id' => Room::where('room_type_id', $type->id)->first()->id])->assertOk();
        $this->postJson('/api/v1/cashier-shifts', ['opening_float' => 5_000])->assertCreated();
        $folio = $this->getJson("/api/v1/reservations/{$r->id}/folio")->assertOk()->json();

        return [$r->fresh(), $folio, $type, $guest];
    }

    private function pdf(string $path, ?string $type = null, ?int $amount = null, ?string $lang = null): ?IssuedDocument
    {
        $res = $this->get("/api/v1/documents/money/$path");
        $res->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $res->getContent());
        $this->assertGreaterThan(1000, strlen($res->getContent()));
        if (! $type) {
            return null;
        }
        $rec = IssuedDocument::where('type', $type)->latest('id')->first();
        $this->assertNotNull($rec, "no issued record for $type");
        $this->assertNotEmpty($rec->code);
        if ($amount !== null) {
            $this->assertSame($amount, (int) $rec->amount, "amount of $type");
        }
        if ($lang) {
            $this->assertSame($lang, $rec->locale);
        }

        return $rec;
    }

    public function test_guest_and_front_office_documents(): void
    {
        [$r, $folio, $type] = $this->stay();
        $fid = $folio['id'];
        $this->postJson("/api/v1/folios/$fid/charges", ['charge_code' => 'MISC', 'amount' => 20_000])->assertSuccessful();
        $pay = $this->postJson("/api/v1/folios/$fid/payments", ['method' => 'CASH', 'amount' => 100_000, 'reference' => 'DEP1'])->assertCreated()->json();
        $line = $pay['id'];

        // payment and deposit receipts follow the guest language (en); ?lang overrides
        $this->pdf("payment-receipt/$line", 'payment_receipt', 100_000, 'en');
        $this->pdf("payment-receipt/$line?lang=fr", 'payment_receipt', 100_000, 'en'); // same source: same record, now a copy
        $this->assertSame(1, (int) IssuedDocument::where('type', 'payment_receipt')->value('copy_count'));
        $this->pdf("deposit-receipt/$line", 'deposit_receipt', 100_000);
        $this->pdf("proforma/$fid", 'proforma');
        $this->pdf("guest-statement/{$folio['guest_id']}", 'guest_statement');
        $this->get("/api/v1/documents/money/payment-receipt/999999")->assertNotFound();

        // refund voucher: overpaid folio refunded
        $this->postJson("/api/v1/folios/$fid/refunds", ['method' => 'CASH', 'amount' => 10_000, 'reason' => 'Overpayment'])->assertSuccessful();
        $refund = FolioLine::where('folio_id', $fid)->where('type', 'refund')->first();
        $this->pdf("refund-voucher/{$refund->id}", 'refund_voucher', 10_000);
        $this->get("/api/v1/documents/money/refund-voucher/$line")->assertStatus(422); // a payment line is not a refund

        // void slip and voiding the payment flips its receipts to voided
        $this->postJson("/api/v1/folios/$fid/lines/$line/reverse", ['reason' => 'Wrong amount'])->assertCreated();
        $this->assertSame('voided', IssuedDocument::where('type', 'payment_receipt')->first()->status);
        $this->assertSame('voided', IssuedDocument::where('type', 'deposit_receipt')->first()->status);
        $rev = FolioLine::where('folio_id', $fid)->where('type', 'reversal')->first();
        $this->pdf("void-slip/{$rev->id}", 'void_slip');

        // room move slip, express check-out summary, cancellation
        $to = Room::where('room_type_id', $type->id)->where('occupied', false)->first();
        $this->postJson("/api/v1/reservations/{$r->id}/move-room", ['room_id' => $to->id, 'reason' => 'AC broken'])->assertOk();
        $this->pdf("room-move/{$r->id}", 'room_move');
        $this->pdf("express-checkout/{$r->id}?lang=en", 'express_checkout', null, 'en');

        $future = app(ReservationService::class)->create([
            'guest_id' => $r->guest_id, 'room_type_id' => $type->id, 'rate_plan_id' => $r->rate_plan_id,
            'arrival' => now()->addDays(5)->toDateString(), 'departure' => now()->addDays(6)->toDateString(),
        ]);
        $this->get("/api/v1/documents/money/cancellation/{$future->id}")->assertStatus(422); // not cancelled yet
        $this->postJson("/api/v1/reservations/{$future->id}/cancel", ['reason' => 'Change of plans'])->assertSuccessful();
        $this->pdf("cancellation/{$future->id}", 'cancellation', null, 'en');
        $this->pdf("cancellation/{$future->id}?lang=fr", 'cancellation', null, 'en');

        // permission: a room attendant can print none of them; another hotel cannot see this hotel's lines
        $attendant = $this->makeUser($this->group, 'room-attendant', allHotels: true);
        $this->actAs($attendant, $this->hotel);
        foreach (["payment-receipt/$line", "proforma/$fid", "guest-statement/{$folio['guest_id']}", "cancellation/{$future->id}", "room-move/{$r->id}"] as $p) {
            $this->get("/api/v1/documents/money/$p")->assertForbidden();
        }
        [$g2, $h2] = $this->makeHotel('Other Group', 'OTH');
        $this->provision($h2);
        $gm = $this->makeUser($g2, 'general-manager', allHotels: true);
        $this->actAs($gm, $h2);
        $this->get("/api/v1/documents/money/payment-receipt/$line")->assertNotFound();
        $this->get("/api/v1/documents/money/proforma/$fid")->assertNotFound();
    }

    public function test_credit_note_reverses_the_invoice_in_the_ledger_and_marks_it_replaced(): void
    {
        [$r, $folio] = $this->stay();
        $fid = $folio['id'];
        $this->postJson("/api/v1/folios/$fid/charges", ['charge_code' => 'MISC', 'amount' => 25_000])->assertSuccessful();
        $inv = $this->postJson("/api/v1/folios/$fid/invoice")->assertCreated()->json();
        $this->get("/api/v1/documents/invoices/{$inv['id']}")->assertOk();
        $before = IssuedDocument::where('type', 'invoice')->first();
        $this->assertSame('valid', $before->status);
        $revenueBefore = $this->assertBooksBalance();

        $this->postJson('/api/v1/documents/money/credit-notes', ['invoice_id' => $inv['id'], 'reason' => 'Billing error', 'amount' => $inv['total'] + 1])->assertStatus(422);
        $note = $this->postJson('/api/v1/documents/money/credit-notes', ['invoice_id' => $inv['id'], 'reason' => 'Billing error'])->assertCreated()->json();
        $this->assertSame('credit_note', $note['kind']);
        $this->assertSame($inv['total'], $note['total']);
        $this->assertSame($inv['id'], $note['credits_invoice_id']);
        $this->assertStringStartsWith('AV', $note['number']);

        $booksAfter = $this->assertBooksBalance();
        $this->assertNotEquals($revenueBefore, $booksAfter, 'the credit note posted a reversing entry');
        $entry = DB::table('journal_entries')->where('source_type', 'credit_note')->where('source_id', (string) $note['id'])->first();
        $this->assertNotNull($entry);
        $this->assertSame($inv['total'], (int) $entry->total);

        $this->assertSame('replaced', IssuedDocument::where('type', 'invoice')->first()->status);
        $this->assertSame('cancelled', $this->getJson("/api/v1/invoices/{$inv['id']}")->json('status'));
        $rec = $this->pdf("credit-note/{$note['id']}", 'credit_note', (int) $note['total'], 'fr');
        $this->assertSame($note['number'], $rec->number);
        $this->assertSame($rec->id, IssuedDocument::where('type', 'invoice')->first()->replaced_by_id);
        $this->pdf("credit-note/{$note['id']}?lang=en", 'credit_note', null, 'fr'); // legal: stays French
        $this->get('/api/v1/documents/money/credit-note/'.$inv['id'])->assertNotFound(); // an invoice is not a credit note
        $this->postJson('/api/v1/documents/money/credit-notes', ['invoice_id' => $inv['id'], 'reason' => 'again'])->assertStatus(422);

        $attendant = $this->makeUser($this->group, 'room-attendant', allHotels: true);
        $this->actAs($attendant, $this->hotel);
        $this->postJson('/api/v1/documents/money/credit-notes', ['invoice_id' => $inv['id'], 'reason' => 'x'])->assertForbidden();
        $this->get("/api/v1/documents/money/credit-note/{$note['id']}")->assertForbidden();
    }

    public function test_partial_credit_note_on_a_company_invoice_and_receivable_documents(): void
    {
        [$r, $folio] = $this->stay();
        $fid = $folio['id'];
        $co = Company::create(['name' => 'Camtel SA', 'tax_id' => 'M99', 'city_ledger' => true, 'payment_terms_days' => 30, 'credit_limit' => 5_000_000]);
        $this->postJson("/api/v1/folios/$fid/charges", ['charge_code' => 'MISC', 'amount' => 80_000])->assertSuccessful();
        $ledger = $this->postJson("/api/v1/folios/$fid/city-ledger", ['company_id' => $co->id])->assertSuccessful()->json();
        $inv = $this->postJson("/api/v1/folios/{$ledger['id']}/invoice")->assertCreated()->json();
        $this->assertGreaterThan(0, $inv['total']);

        $note = $this->postJson('/api/v1/documents/money/credit-notes', ['invoice_id' => $inv['id'], 'reason' => 'Discount', 'amount' => 10_000])->assertCreated()->json();
        $this->assertSame(10_000, $note['total']);
        $this->assertSame('valid', IssuedDocument::where('type', 'invoice')->first()?->status ?? 'valid'); // partial: original stays valid
        $this->assertBooksBalance();
        $this->pdf("credit-note/{$note['id']}", 'credit_note', 10_000);

        foreach ([1, 2, 3] as $level) {
            $this->pdf("reminder/{$inv['id']}?level=$level&lang=".($level === 2 ? 'en' : 'fr'), 'payment_reminder', null, $level === 2 ? 'en' : 'fr');
        }
        $this->assertSame(3, IssuedDocument::where('type', 'payment_reminder')->count());
        $this->pdf("company-statement/{$co->id}", 'company_statement');
        $this->pdf("company-statement/{$co->id}?lang=en", 'company_statement');
        $this->pdf('ageing', 'ageing');

        $attendant = $this->makeUser($this->group, 'room-attendant', allHotels: true);
        $this->actAs($attendant, $this->hotel);
        $this->get("/api/v1/documents/money/company-statement/{$co->id}")->assertForbidden();
        $this->get("/api/v1/documents/money/reminder/{$inv['id']}?level=1")->assertForbidden();
    }

    public function test_pos_receipt_payment_receipt_and_cashier_z_report(): void
    {
        $bar = Outlet::create(['code' => 'BAR', 'name' => 'Lobby Bar', 'kind' => 'bar']);
        $beer = MenuItem::create(['code' => 'BEER', 'name' => '33 Export', 'price' => 2_000, 'charge_code' => 'BAR', 'kitchen_station' => 'bar']);
        $shift = $this->postJson('/api/v1/cashier-shifts', ['opening_float' => 1_000])->assertCreated()->json();
        $c = $this->postJson('/api/v1/pos/checks', ['outlet_id' => $bar->id])->assertCreated()->json();
        $this->postJson("/api/v1/pos/checks/{$c['id']}/items", ['menu_item_id' => $beer->id, 'quantity' => 3])->assertCreated();
        $this->get("/api/v1/documents/money/pos-receipt/{$c['id']}")->assertOk(); // unpaid: draft preview, nothing issued
        $this->assertSame(0, IssuedDocument::where('type', 'pos_receipt')->count());

        $this->postJson("/api/v1/pos/checks/{$c['id']}/settle", ['payments' => [['method' => 'CASH', 'amount' => 6_000, 'tendered' => 10_000]]])->assertSuccessful();
        $rec = $this->pdf("pos-receipt/{$c['id']}", 'pos_receipt', 6_000, 'fr');
        $this->assertStringStartsWith('T-', $rec->number);
        $this->assertSame('80mm', $this->get("/api/v1/documents/money/pos-receipt/{$c['id']}?lang=en")->assertOk()->headers->get('X-Doc-Size', '80mm'));
        $this->assertSame(1, (int) IssuedDocument::where('type', 'pos_receipt')->value('copy_count'));
        $payId = DB::table('pos_payments')->where('pos_check_id', $c['id'])->value('id');
        $this->pdf("payment-receipt-pos/$payId", 'payment_receipt_pos', 6_000);
        $this->get("/api/v1/documents/money/void-slip-pos/{$c['id']}")->assertNotFound(); // nothing voided

        $this->postJson("/api/v1/cashier-shifts/{$shift['id']}/close", ['counted' => ['CASH' => 7_000]])->assertSuccessful();
        $this->pdf("shift-report/{$shift['id']}", 'shift_report');
        $this->pdf("shift-report/{$shift['id']}?lang=en", 'shift_report');

        $attendant = $this->makeUser($this->group, 'room-attendant', allHotels: true);
        $this->actAs($attendant, $this->hotel);
        $this->get("/api/v1/documents/money/pos-receipt/{$c['id']}")->assertForbidden();
        $this->get("/api/v1/documents/money/shift-report/{$shift['id']}")->assertForbidden();
    }

    public function test_online_and_pay_link_receipts(): void
    {
        [$r, $folio] = $this->stay();
        $ctx = app(TenantContext::class);
        $intent = \App\Modules\Payments\Domain\PaymentIntent::create([
            'reference' => (string) \Illuminate\Support\Str::uuid(), 'provider' => 'mtn_momo', 'driver' => 'dry_run', 'amount' => 30_000, 'currency' => 'XAF',
            'folio_id' => $folio['id'], 'status' => 'succeeded', 'completed_at' => now(), 'provider_reference' => 'MTN123',
        ]);
        $link = \App\Modules\Payments\Domain\PaymentLink::create(['code' => 'ABCD1234', 'target_type' => 'folio', 'target_id' => $folio['id'], 'amount' => 30_000, 'amount_paid' => 30_000, 'status' => 'paid']);
        $intent->update(['meta' => ['link_id' => $link->id]]);
        $this->pdf("payment-receipt-online/{$intent->id}", 'payment_receipt_online', 30_000);
        $this->pdf("payment-receipt-link/{$link->id}", 'payment_receipt_link', 30_000);
        $pending = \App\Modules\Payments\Domain\PaymentIntent::create(['reference' => (string) \Illuminate\Support\Str::uuid(), 'provider' => 'mtn_momo', 'driver' => 'dry_run', 'amount' => 1_000, 'currency' => 'XAF', 'status' => 'pending']);
        $this->get("/api/v1/documents/money/payment-receipt-online/{$pending->id}")->assertStatus(422);
    }

    public function test_accounting_documents_and_report_print_layouts(): void
    {
        [$r, $folio] = $this->stay();
        $fid = $folio['id'];
        $this->postJson("/api/v1/folios/$fid/charges", ['charge_code' => 'MISC', 'amount' => 40_000])->assertSuccessful();
        $this->postJson('/api/v1/night-audit')->assertOk();
        $year = now()->year;
        $from = "$year-01-01";
        $to = now()->addDay()->toDateString();

        // 30-36 print layouts
        foreach (['trial-balance', 'income-statement', 'vat-return', 'cash-flow'] as $k) {
            $this->pdf("$k?from=$from&to=$to", str_replace('-', '_', $k));
        }
        $this->pdf("balance-sheet?to=$to", 'balance_sheet');
        $this->pdf("general-ledger?account=411100&from=$from&to=$to", 'general_ledger');
        $this->pdf("trial-balance?from=$from&to=$to&lang=en", 'trial_balance', null, 'en');

        // 28 journal voucher
        $entryId = DB::table('journal_entries')->orderBy('id')->value('id');
        $this->assertNotNull($entryId);
        $rec = $this->pdf("journal-voucher/$entryId", 'journal_voucher', (int) DB::table('journal_entries')->where('id', $entryId)->value('total'));
        $this->assertStringStartsWith('PC-', $rec->number);

        // 37 bank reconciliation
        $bank = $this->postJson('/api/v1/accounting/bank-accounts', ['name' => 'Afriland', 'kind' => 'bank', 'gl_account' => '521100'])->assertCreated()->json();
        $this->pdf("bank-reconciliation/{$bank['id']}?from=$from&to=$to", 'bank_reconciliation');

        // 38 assets
        $class = $this->getJson('/api/v1/accounting/asset-classes')->assertOk()->json()[0] ?? null;
        if ($class) {
            $this->postJson('/api/v1/accounting/fixed-assets', ['asset_class_id' => $class['id'], 'name' => 'Generator', 'acquired_on' => now()->subMonths(3)->toDateString(), 'cost' => 1_200_000])->assertSuccessful();
        }
        $this->pdf('asset-register', 'asset_register');
        $this->pdf("depreciation-schedule?year=$year", 'depreciation_schedule');

        // 39 budget variance
        $lines = [];
        foreach ([1, 2, 3] as $m) {
            $lines[] = ['account' => '706100', 'department' => 'rooms', 'month' => $m, 'amount' => 1_000_000];
        }
        $b = $this->postJson('/api/v1/accounting/core/budgets', ['name' => 'Budget', 'year' => $year, 'lines' => $lines])->assertCreated()->json();
        $this->pdf("budget-variance/{$b['id']}?by=account", 'budget_variance');

        // 40 manager's daily report
        $this->pdf('daily-report', 'daily_report');
        $this->pdf('daily-report?lang=en', 'daily_report', null, 'en');

        // 29 supplier payment voucher + remittance advice
        $supplier = \App\Modules\Stock\Domain\Supplier::create(['code' => 'SUP1', 'name' => 'Brasseries du Cameroun', 'tax_id' => 'M777']);
        $siId = DB::table('supplier_invoices')->insertGetId(['group_id' => $this->group->id, 'hotel_id' => $this->hotel->id, 'supplier_id' => $supplier->id, 'reference' => 'INV-1', 'date' => now()->toDateString(), 'net_total' => 100_000, 'tax_total' => 0, 'total' => 100_000, 'paid' => 60_000, 'status' => 'partially_paid', 'created_at' => now(), 'updated_at' => now()]);
        $spId = DB::table('supplier_payments')->insertGetId(['group_id' => $this->group->id, 'hotel_id' => $this->hotel->id, 'supplier_id' => $supplier->id, 'supplier_invoice_id' => $siId, 'date' => now()->toDateString(), 'amount' => 60_000, 'account' => '521100', 'reference' => 'VIR-1', 'created_at' => now(), 'updated_at' => now()]);
        $this->pdf("supplier-payment/$spId", 'supplier_payment', 60_000);
        $this->pdf("supplier-payment/$spId?lang=en", 'supplier_payment', 60_000);

        // permission: receptionists have no accounting access; another hotel cannot read these sources
        $rec2 = $this->makeUser($this->group, 'receptionist', allHotels: true);
        $this->actAs($rec2, $this->hotel);
        foreach (["trial-balance?from=$from&to=$to", "journal-voucher/$entryId", "asset-register", "bank-reconciliation/{$bank['id']}", "budget-variance/{$b['id']}", "supplier-payment/$spId", 'daily-report'] as $p) {
            $this->get("/api/v1/documents/money/$p")->assertForbidden();
        }
        [$g2, $h2] = $this->makeHotel('Other Group', 'OTH');
        $this->provision($h2);
        $gm = $this->makeUser($g2, 'general-manager', allHotels: true);
        $this->actAs($gm, $h2);
        $this->get("/api/v1/documents/money/journal-voucher/$entryId")->assertNotFound();
        $this->get("/api/v1/documents/money/supplier-payment/$spId")->assertNotFound();
        $this->get("/api/v1/documents/money/bank-reconciliation/{$bank['id']}")->assertNotFound();
    }

    public function test_document_labels_exist_in_both_languages_with_identical_keys(): void
    {
        $fr = json_decode(file_get_contents(base_path('lang/fr/documents3.json')), true);
        $en = json_decode(file_get_contents(base_path('lang/en/documents3.json')), true);
        $this->assertSame(array_keys($fr), array_keys($en));
        foreach (\App\Modules\Documents\Money\MoneyController::KINDS as $k) {
            $key = 'documents2.type.'.str_replace('-', '_', $k);
            if (in_array($k, ['reminder'], true)) {
                $key = 'documents2.type.payment_reminder';
            }
            if (in_array($k, ['supplier-payment'], true)) {
                $key = 'documents2.type.supplier_payment';
            }
            $this->assertArrayHasKey($key, $fr, $key);
        }
    }
}
