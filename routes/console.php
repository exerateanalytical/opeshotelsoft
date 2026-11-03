<?php

use App\Modules\Loyalty\Application\LoyaltyService;
use App\Modules\Loyalty\Domain\Program;
use App\Modules\Maintenance\Application\MaintenanceService;
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
