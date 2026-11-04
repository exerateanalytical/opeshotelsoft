<?php

namespace Tests\Feature\Gapn;

use App\Models\User;
use App\Modules\Tenancy\Domain\Hotel;
use App\Platform\Tenancy\TenantContext;
use Database\Seeders\DemoGapSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** GAP-N: DemoGapSeeder fills the empty demo screens and every affected list endpoint still answers 200 for a general manager. */
class DemoGapSeederTest extends TestCase
{
    private const ENDPOINTS = [
        '/api/v1/accounting/bank-accounts', '/api/v1/accounting/core/budgets', '/api/v1/accounting/fixed-assets', '/api/v1/accounting/asset-classes',
        '/api/v1/accounting3/bank-rules', '/api/v1/accounting3/close/runs', '/api/v1/accounting3/close/statement-packs', '/api/v1/accounting3/dunning/notices', '/api/v1/accounting3/dunning/candidates',
        '/api/v1/accounting3/payables/approval-chains', '/api/v1/accounting3/payables/approvals/inbox', '/api/v1/accounting3/tax/payroll-filings', '/api/v1/accounting3/tax/vat-returns',
        '/api/v1/assets', '/api/v1/maintenance/work-orders', '/api/v1/maintenance/schedules', '/api/v1/workforce/rosters', '/api/v1/workforce/dashboard', '/api/v1/hr/shifts',
        '/api/v1/messaging/conversations', '/api/v1/messaging/quick-replies', '/api/v1/service-requests', '/api/v1/service-requests/feed',
        '/api/v1/hr/talent/posts', '/api/v1/hr/talent/applicants', '/api/v1/hr/talent/certifications', '/api/v1/hr/talent/sessions', '/api/v1/hr/talent/courses', '/api/v1/people/cycles',
        '/api/v1/payments/links', '/api/v1/payments/intents', '/api/v1/payments/gateways', '/api/v1/experience/reviews', '/api/v1/housekeeping/lost-found',
        '/api/v1/revenue/competitor-rates', '/api/v1/revenue/walks', '/api/v1/revenue/contracts', '/api/v1/blocks', '/api/v1/loyalty/promotions',
        '/api/v1/stock-ops/minibar/pars', '/api/v1/payables/invoices', '/api/v1/payables/ageing', '/api/v1/insights/alerts', '/api/v1/insights/digests',
    ];

    public function test_seeder_fills_gaps_and_screens_still_load(): void
    {
        $this->seed(DemoSeeder::class);
        $this->seed(DemoGapSeeder::class);
        $before = $this->counts();
        $this->seed(DemoGapSeeder::class); // idempotent re-run
        $this->assertSame($before, $this->counts());

        foreach (['bank_accounts', 'bank_statement_lines', 'budgets', 'budget_lines', 'fixed_assets', 'assets', 'work_orders', 'pm_schedules', 'workforce_rosters', 'whatsapp_conversations', 'whatsapp_messages',
            'tal_job_posts', 'lost_found_items', 'competitor_rates', 'room_blocks', 'supplier_invoices', 'invoice_approvals', 'service_requests'] as $t) {
            $this->assertGreaterThan(0, $before[$t] ?? 0, "$t should be seeded");
        }

        $dla = app(TenantContext::class)->withoutScope(fn () => Hotel::where('code', 'DLA')->firstOrFail());
        $gm = User::where('email', 'gm@demo.opeshotelsoft.test')->firstOrFail();
        $this->actAs($gm, $dla);
        foreach (self::ENDPOINTS as $url) {
            $r = $this->getJson($url.'?from='.now()->subDays(7)->toDateString().'&to='.now()->addDays(7)->toDateString());
            $this->assertSame(200, $r->status(), "$url -> ".substr($r->getContent(), 0, 300));
        }
        $bank = DB::table('bank_accounts')->where('hotel_id', $dla->id)->value('id');
        $this->getJson("/api/v1/accounting/bank-accounts/$bank/reconciliation?from=".now()->subMonth()->toDateString().'&to='.now()->toDateString())->assertOk();
        $this->getJson("/api/v1/accounting/bank-accounts/$bank/statement-lines")->assertOk();
    }

    private function counts(): array
    {
        $out = [];
        foreach (['bank_accounts', 'bank_statement_lines', 'budgets', 'budget_lines', 'fixed_assets', 'assets', 'work_orders', 'pm_schedules', 'workforce_rosters', 'whatsapp_conversations', 'whatsapp_messages',
            'tal_job_posts', 'lost_found_items', 'competitor_rates', 'room_blocks', 'supplier_invoices', 'invoice_approvals', 'service_requests', 'close_tasks', 'vat_returns', 'dunning_notices', 'payment_links'] as $t) {
            $out[$t] = DB::table($t)->count();
        }

        return $out;
    }
}
