<?php

namespace Tests\Feature\Accounting;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** GAP-A: endpoints wired by the accounting core screens (FX exposure, entry attachments + audit, budget detail + group, bank import history, ageing export). */
class GapaAccountingUiTest extends TestCase
{
    private function outsider()
    {
        return $this->makeUser($this->group, 'room-attendant', hotels: [$this->hotel]);
    }

    public function test_finance_pages_render_for_accounting_and_are_forbidden_to_others(): void
    {
        foreach (['/finance', '/finance/budgets'] as $url) {
            $this->get($url)->assertOk();
        }
        $this->get('/finance/bank-feed')->assertOk();
        $this->actAs($this->outsider(), $this->hotel);
        foreach (['/finance', '/finance/budgets', '/finance/bank-feed'] as $url) {
            $this->get($url)->assertForbidden();
        }
    }

    public function test_fx_exposure_and_month_end(): void
    {
        $this->getJson('/api/v1/accounting/core/fx/exposure?date=2026-03-31')->assertOk()->assertJsonStructure(['date', 'rates', 'positions', 'gain', 'loss']);
        $this->postJson('/api/v1/accounting/core/fx/month-end', ['date' => '2026-03-31'])->assertOk()->assertJsonPath('status', 'nothing-to-revalue');

        $this->actAs($this->outsider(), $this->hotel);
        $this->getJson('/api/v1/accounting/core/fx/exposure?date=2026-03-31')->assertForbidden();
        $this->postJson('/api/v1/accounting/core/fx/month-end', ['date' => '2026-03-31'])->assertForbidden();
    }

    public function test_entry_attachments_and_audit_trail(): void
    {
        Storage::fake('local');
        $entry = $this->postJson('/api/v1/accounting/entries', ['journal' => 'OD', 'date' => '2026-02-02', 'description' => 'T', 'lines' => [
            ['account' => '521100', 'debit' => 1_000], ['account' => '706800', 'credit' => 1_000],
        ]])->assertCreated()->json('id');

        $this->post("/api/v1/accounting/core/entries/$entry/attachments", ['file' => UploadedFile::fake()->create('piece.pdf', 10, 'application/pdf')], ['Accept' => 'application/json'])->assertCreated();
        $att = $this->getJson("/api/v1/accounting/core/entries/$entry/attachments")->assertOk()->json();
        $this->assertCount(1, $att);
        $this->get("/api/v1/accounting/core/attachments/{$att[0]['id']}")->assertOk();
        $audit = $this->getJson("/api/v1/accounting/core/entries/$entry/audit")->assertOk()->json();
        $this->assertContains('attachment_added', array_column($audit['trail'], 'action'));

        $this->actAs($this->outsider(), $this->hotel);
        $this->getJson("/api/v1/accounting/core/entries/$entry/attachments")->assertForbidden();
        $this->getJson("/api/v1/accounting/core/entries/$entry/audit")->assertForbidden();
        $this->get("/api/v1/accounting/core/attachments/{$att[0]['id']}", ['Accept' => 'application/json'])->assertForbidden();
        $this->post("/api/v1/accounting/core/entries/$entry/attachments", ['file' => UploadedFile::fake()->create('x.pdf', 5, 'application/pdf')], ['Accept' => 'application/json'])->assertForbidden();
    }

    public function test_budget_detail_and_group_rollup(): void
    {
        $b = $this->postJson('/api/v1/accounting/core/budgets', ['name' => 'B26', 'year' => 2026, 'lines' => [['account' => '706100', 'month' => 1, 'amount' => 500_000]]])->assertCreated()->json();
        $this->getJson("/api/v1/accounting/core/budgets/{$b['id']}")->assertOk()->assertJsonPath('name', 'B26')->assertJsonCount(1, 'lines');
        $this->postJson("/api/v1/accounting/core/budgets/{$b['id']}/approve")->assertOk();

        $fd = $this->makeUser($this->group, 'finance-director', allHotels: true);
        $this->actAs($fd, $this->hotel);
        $this->getJson('/api/v1/accounting/core/budgets/group?year=2026')->assertOk()->assertJsonPath('year', 2026);

        $this->actAs($this->outsider(), $this->hotel);
        $this->getJson("/api/v1/accounting/core/budgets/{$b['id']}")->assertForbidden();
        $this->getJson('/api/v1/accounting/core/budgets/group?year=2026')->assertForbidden();
    }

    public function test_bank_import_history_and_ageing_export(): void
    {
        $bank = $this->postJson('/api/v1/accounting/bank-accounts', ['name' => 'Afriland', 'kind' => 'bank', 'gl_account' => '521100'])->assertCreated()->json();
        $this->getJson("/api/v1/accounting/bank-accounts/{$bank['id']}/imports")->assertOk()->assertJsonCount(0);
        $this->get('/api/v1/exports/ageing?format=csv')->assertOk();

        $this->actAs($this->outsider(), $this->hotel);
        $this->getJson("/api/v1/accounting/bank-accounts/{$bank['id']}/imports")->assertForbidden();
        $this->getJson('/api/v1/exports/ageing?format=csv')->assertForbidden();
    }
}
