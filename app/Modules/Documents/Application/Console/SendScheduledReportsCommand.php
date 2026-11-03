<?php

namespace App\Modules\Documents\Application\Console;

use App\Modules\Documents\Application\ScheduledReportRunner;
use App\Modules\Documents\Domain\ScheduledReport;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Console\Command;

class SendScheduledReportsCommand extends Command
{
    protected $signature = 'reports:send-scheduled {--id= : Run one schedule now, due or not}';

    protected $description = 'Render due scheduled reports and email them to their recipients';

    public function handle(TenantContext $ctx, ScheduledReportRunner $runner): int
    {
        $due = $ctx->withoutScope(fn () => ScheduledReport::query()
            ->when($this->option('id'), fn ($q, $id) => $q->whereKey($id), fn ($q) => $q->where('active', true)->where('next_run_at', '<=', now()))
            ->get());

        foreach ($due as $s) {
            $ok = $runner->run($s);
            $this->line(($ok ? 'sent  ' : 'FAILED ')."#{$s->id} {$s->name}".($ok ? '' : ': '.$s->last_error));
        }
        $this->info($due->count().' scheduled report(s) processed.');

        return self::SUCCESS;
    }
}
