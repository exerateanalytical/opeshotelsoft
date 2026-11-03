<?php

use App\Modules\Edge\Application\EdgeInstaller;
use App\Modules\Edge\Application\EdgeSyncer;
use App\Modules\Loyalty\Application\LoyaltyService;
use App\Modules\Loyalty\Domain\Program;
use App\Modules\Maintenance\Application\MaintenanceService;
use App\Modules\Platform\Ops\Application\BackupService;
use App\Modules\Tenancy\Domain\Hotel;
use App\Platform\Events\EventBus;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

/** Run a callback once per active hotel with the tenant context set. */
$eachHotel = function (callable $fn): void {
    $ctx = app(TenantContext::class);
    foreach ($ctx->withoutScope(fn () => Hotel::where('status', 'active')->get()) as $hotel) {
        $ctx->set($hotel->group_id, $hotel->id, [$hotel->id]);
        $fn($hotel);
    }
};

Artisan::command('events:relay', function () {
    $n = app(EventBus::class)->relayPending();
    $this->info("Relayed $n pending event(s).");
})->purpose('Dispatch outbox events that were stored but not yet dispatched (crash recovery)');

Artisan::command('maintenance:preventive', function () use ($eachHotel) {
    $eachHotel(fn ($h) => $this->info($h->code.': '.app(MaintenanceService::class)->runPreventive().' work order(s) opened'));
})->purpose('Open work orders for due preventive maintenance schedules');

Artisan::command('loyalty:expire', function () {
    $ctx = app(TenantContext::class);
    foreach ($ctx->withoutScope(fn () => Program::where('active', true)->get()) as $program) {
        $ctx->set($program->group_id);
        $this->info("Group {$program->group_id}: ".app(LoyaltyService::class)->expire().' point(s) expired');
    }
})->purpose('Expire loyalty points past their expiry date');

Schedule::command('events:relay')->everyMinute()->withoutOverlapping();
Schedule::command('maintenance:preventive')->dailyAt('05:00');
Schedule::command('loyalty:expire')->dailyAt('02:30');
Schedule::command('notifications:pre-arrival')->dailyAt('10:00');
Schedule::command('notifications:retry')->everyMinute();
Schedule::command('reports:send-scheduled')->everyFiveMinutes();

// --- P5 production operations: encrypted DB backups + restore drill ---
Artisan::command('ops:backup {--connection= : Database connection (default: database.default)}', function () {
    $r = app(BackupService::class)->backup($this->option('connection') ?: null);
    $this->info('Backup written: '.basename($r['file']).' ('.number_format($r['bytes']).' bytes)'
        .($r['copied'] ? ', copied to '.config('ops.backup.disk') : '').", {$r['pruned']} old file(s) pruned");
})->purpose('pg_dump + gzip + AES-256 encrypt the database, apply retention, optional copy to BACKUP_DISK');

Artisan::command('ops:backup:verify {--file= : Backup file (default: latest)} {--connection=} {--keep : Keep the scratch database}', function () {
    $r = app(BackupService::class)
        ->verify($this->option('file') ?: null, $this->option('connection') ?: null, (bool) $this->option('keep'));
    $this->info('Restore drill OK: '.basename($r['file'])." -> {$r['database']} ({$r['tables']} tables, {$r['migrations']} migrations)");
})->purpose('Restore the latest (or given) backup into a scratch database and sanity-check it');

Schedule::command('ops:backup')->dailyAt(config('ops.backup.schedule_at', '01:30'))->withoutOverlapping()->onOneServer();
Schedule::command('ops:backup:verify')->weeklyOn(0, '03:30')->withoutOverlapping()->onOneServer();

// P6 hotel edge node (docs/EDGE.md).
Artisan::command('edge:init', function () {
    $r = app(EdgeInstaller::class)->install();
    $this->info("Edge DB ready: {$r['sequences_moved']} sequence(s) moved to {$r['id_offset']}, document numbers from {$r['number_range_start']}.");
})->purpose('Prepare the local edge database (id offsets, document number ranges)');

Artisan::command('edge:sync', function () {
    if (! config('edge.mode')) {
        $this->warn('EDGE_MODE is off; nothing to sync.');

        return 0;
    }
    try {
        $r = app(EdgeSyncer::class)->run();
    } catch (Throwable $e) {
        $this->error('Sync failed: '.$e->getMessage());

        return 1;
    }
    $this->info("Pushed {$r['push']['applied']} (skipped {$r['push']['skipped']}, conflicts {$r['push']['conflicts']}); pulled {$r['pull']['applied']} (kept local {$r['pull']['kept_local']}).");

    return 0;
})->purpose('Push local changes to the cloud and pull cloud changes (edge mode)');

Schedule::command('edge:sync')->everyMinute()->withoutOverlapping()->when(fn () => (bool) config('edge.mode'));

Schedule::command('loyalty:daily')->dailyAt('02:40')->withoutOverlapping()->onOneServer();
Schedule::command('insights:scan')->dailyAt('06:00')->withoutOverlapping()->onOneServer();
Schedule::command('whatsapp:journey')->dailyAt('09:00')->withoutOverlapping()->onOneServer();
Schedule::command('stock:expiry-alerts')->dailyAt('06:00')->withoutOverlapping()->onOneServer();

// A-A core ledger: accruals, recurring journals, month-end FX revaluation.
Schedule::command('accounting:run-scheduled')->dailyAt('03:30')->withoutOverlapping();
Schedule::command('servicerequests:escalate')->everyMinute()->withoutOverlapping()->onOneServer();
Schedule::command('talent:scan-certificates')->dailyAt('06:00')->withoutOverlapping()->onOneServer();
Schedule::command('talent:purge-applicants')->dailyAt('03:30')->withoutOverlapping()->onOneServer();
Schedule::command('experience:automate')->hourly()->withoutOverlapping()->onOneServer();
