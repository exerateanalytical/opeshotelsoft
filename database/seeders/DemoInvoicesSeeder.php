<?php

namespace Database\Seeders;

use App\Models\User;
use App\Modules\FrontOffice\Application\FolioService;
use App\Modules\FrontOffice\Domain\Folio;
use App\Modules\FrontOffice\Domain\Invoice;
use App\Modules\Tenancy\Domain\Hotel;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/** Invoices for closed demo stays and company folios, with spread-out dates for ageing reports. Local only. */
class DemoInvoicesSeeder extends Seeder
{
    public function run(): void
    {
        mt_srand(2027);
        $ctx = app(TenantContext::class);
        $actor = User::orderBy('id')->first();
        $folios = app(FolioService::class);
        $made = 0;

        foreach ($ctx->withoutScope(fn () => Hotel::orderBy('id')->get()) as $hotel) {
            $ctx->set($hotel->group_id, $hotel->id, [$hotel->id], $actor);
            $ids = Folio::whereIn('status', ['closed', 'open'])->whereNotIn('id', Invoice::pluck('folio_id')->filter())
                ->where(fn ($q) => $q->where('kind', 'city_ledger')->orWhere(fn ($g) => $g->where('status', 'closed')->whereRaw('(id % 10) < 6')))
                ->pluck('id');
            foreach ($ids as $id) {
                try {
                    $folios->invoice(Folio::find($id));
                    $made++;
                } catch (\Throwable) {
                }
            }
        }
        $ctx->set(null, null, null, $actor);
        // Spread issue dates over the last 75 days so ageing buckets are populated.
        $ctx->withoutScope(function () {
            foreach (Invoice::whereNotNull('due_date')->get() as $inv) {
                $issue = now()->subDays(mt_rand(1, 75));
                DB::table('invoices')->where('id', $inv->id)->update(['issue_date' => $issue->toDateString(), 'due_date' => $issue->copy()->addDays(30)->toDateString()]);
            }
        });
        $this->command?->info("Invoices created: $made");
    }
}
