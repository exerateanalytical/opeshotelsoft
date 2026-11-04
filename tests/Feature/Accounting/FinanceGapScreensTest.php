<?php

namespace Tests\Feature\Accounting;

use App\Modules\Stock\Domain\Supplier;
use Tests\TestCase;

/** Endpoints and pages behind the back-office finance gap screens (tax, payables workflow, month close, dunning, bank rules). */
class FinanceGapScreensTest extends TestCase
{
    private const READS = [
        'accounting3/tax/withholding-rates', 'accounting3/tax/stamp-duty', 'accounting3/close/templates',
        'accounting3/dunning/holds', 'accounting3/bank-rules', 'accounting3/payables/approval-chains',
    ];

    private const PAGES = ['/finance/tax', '/finance/payables-workflow', '/finance/month-close', '/finance/dunning', '/finance/bank-feed'];

    public function test_supplier_tax_profile_can_be_read_back_after_saving(): void
    {
        $s = Supplier::create(['code' => 'GAPB', 'name' => 'Fournisseur Gap', 'tax_id' => 'M000111']);
        $this->getJson('/api/v1/accounting3/tax/withholding-rates')->assertOk();
        $this->getJson("/api/v1/accounting3/tax/suppliers/{$s->id}/profile")->assertOk()->assertJson(['withholding_code' => null, 'niu' => null]);
        $code = $this->getJson('/api/v1/accounting3/tax/withholding-rates')->json('0.code');
        $this->putJson("/api/v1/accounting3/tax/suppliers/{$s->id}/profile", ['withholding_code' => $code, 'exempt' => false, 'niu' => 'P123456789'])->assertOk();
        $this->getJson("/api/v1/accounting3/tax/suppliers/{$s->id}/profile")->assertOk()->assertJson(['withholding_code' => $code, 'niu' => 'P123456789']);
    }

    public function test_screens_and_their_reads_open_for_the_accountant(): void
    {
        $this->actAs($this->makeUser($this->group, 'accountant', true), $this->hotel);
        foreach (self::READS as $path) {
            $this->getJson("/api/v1/$path")->assertOk();
        }
        $s = Supplier::create(['code' => 'GAPC', 'name' => 'Fournisseur C']);
        $this->getJson("/api/v1/accounting3/tax/suppliers/{$s->id}/profile")->assertOk();
        $this->getJson('/api/v1/accounting3/tax/vat-returns/preview?from=2026-10-01&to=2026-10-31')->assertOk()->assertJsonStructure(['lines', 'figures', 'filing_deadline']);
    }

    public function test_bank_rule_edit_and_close_template_edit_round_trip(): void
    {
        $code = \DB::table('accounts')->where('hotel_id', $this->hotel->id)->where('postable', true)->value('code');
        $rule = $this->postJson('/api/v1/accounting3/bank-rules', ['name' => 'Frais', 'pattern' => 'FRAIS', 'account_code' => $code])->assertCreated()->json();
        $this->putJson("/api/v1/accounting3/bank-rules/{$rule['id']}", ['name' => 'Frais bancaires', 'pattern' => 'FRAIS', 'account_code' => $code, 'priority' => 5])->assertOk()->assertJson(['name' => 'Frais bancaires', 'priority' => 5]);

        $tpl = $this->getJson('/api/v1/accounting3/close/templates')->assertOk()->json('templates.0');
        $this->putJson("/api/v1/accounting3/close/templates/{$tpl['id']}", ['title' => 'Rapprocher la banque', 'due_offset_days' => 2])->assertOk();
        $this->assertSame('Rapprocher la banque', collect($this->getJson('/api/v1/accounting3/close/templates')->json('templates'))->firstWhere('id', $tpl['id'])['title']);
    }

    public function test_others_are_refused(): void
    {
        $s = Supplier::create(['code' => 'GAPD', 'name' => 'Fournisseur D']);
        $this->actAs($this->makeUser($this->group, 'receptionist', true), $this->hotel);
        foreach (self::READS as $path) {
            $this->getJson("/api/v1/$path")->assertForbidden();
        }
        $this->getJson("/api/v1/accounting3/tax/suppliers/{$s->id}/profile")->assertForbidden();
        $this->postJson('/api/v1/accounting3/dunning/holds/1/release')->assertForbidden();
        $this->putJson('/api/v1/accounting3/bank-rules/1', [])->assertForbidden();
        $this->postJson('/api/v1/accounting3/close/tasks/1/assign', [])->assertForbidden();
        $this->putJson('/api/v1/accounting3/payables/approval-chains/1', [])->assertForbidden();
        foreach (self::PAGES as $page) {
            $this->get($page)->assertForbidden();
        }
    }

    public function test_pages_render_for_permitted_users(): void
    {
        foreach (self::PAGES as $page) {
            $this->get($page)->assertOk();
        }
    }
}
