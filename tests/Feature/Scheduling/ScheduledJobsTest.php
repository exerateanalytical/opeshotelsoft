<?php

namespace Tests\Feature\Scheduling;

use App\Modules\HR\Domain\Attendance;
use App\Modules\HR\Domain\Employee;
use App\Modules\HR\Domain\LeaveType;
use App\Modules\HR\Workforce\Domain\Anomaly;
use App\Modules\HR\Workforce\Domain\LeaveLedger;
use App\Modules\HR\Workforce\Domain\LeavePolicy;
use App\Modules\HR\Workforce\Application\ClockService;
use App\Modules\Tenancy\Domain\Hotel;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class ScheduledJobsTest extends TestCase
{
    private Hotel $hotelB;

    private array $emp = [];

    protected function setUp(): void
    {
        parent::setUp();
        [$gB, $this->hotelB] = $this->makeHotel('Yaounde Test', 'YDE');
        foreach ([$this->hotel, $this->hotelB] as $h) {
            $this->in($h, function () use ($h) {
                $type = LeaveType::firstOrCreate(['code' => 'AL'], ['name' => 'Annual', 'paid' => true, 'accrues' => true]);
                LeavePolicy::firstOrCreate(['leave_type_id' => $type->id], ['accrual_per_month' => 1.5, 'carry_over_max' => 5]);
                $e = Employee::create(['number' => 'E'.$h->id, 'first_name' => 'A', 'last_name' => 'B'.$h->id, 'hire_date' => now()->subYears(2)->toDateString(), 'status' => 'active']);
                $this->emp[$h->id] = $e->id;
                Attendance::create(['employee_id' => $e->id, 'date' => now()->subDay()->toDateString(), 'clock_in' => now()->subHours(30)]);
            });
        }
    }

    private function in(Hotel $h, callable $fn): mixed
    {
        $ctx = app(TenantContext::class);
        $ctx->set($h->group_id, $h->id, [$h->id]);

        return $fn();
    }

    private function rows(Hotel $h, string $model, array $where = []): int
    {
        return $this->in($h, fn () => $model::where($where)->count());
    }

    public function test_clock_out_scan_runs_for_both_groups_and_is_idempotent(): void
    {
        $this->assertSame(0, Artisan::call('workforce:scan-clock-out'));
        $this->assertSame(1, $this->rows($this->hotel, Anomaly::class));
        $this->assertSame(1, $this->rows($this->hotelB, Anomaly::class));
        Artisan::call('workforce:scan-clock-out');
        $this->assertSame(1, $this->rows($this->hotel, Anomaly::class));
        $this->assertSame(1, $this->rows($this->hotelB, Anomaly::class));
    }

    public function test_hotel_option_limits_to_one_tenant(): void
    {
        Artisan::call('workforce:scan-clock-out', ['--hotel' => $this->hotelB->id]);
        $this->assertSame(0, $this->rows($this->hotel, Anomaly::class));
        $this->assertSame(1, $this->rows($this->hotelB, Anomaly::class));
    }

    public function test_dry_run_changes_nothing(): void
    {
        Artisan::call('workforce:scan-clock-out', ['--dry-run' => true]);
        $this->assertSame(0, $this->rows($this->hotel, Anomaly::class));
        $this->assertStringContainsString('dry run', Artisan::output());
    }

    public function test_leave_accrual_two_hotels_idempotent(): void
    {
        $this->assertSame(0, Artisan::call('workforce:leave-accrue', ['--month' => '2026-11']));
        $this->assertSame(1, $this->rows($this->hotel, LeaveLedger::class, ['kind' => 'accrual']));
        $this->assertSame(1, $this->rows($this->hotelB, LeaveLedger::class, ['kind' => 'accrual']));
        Artisan::call('workforce:leave-accrue', ['--month' => '2026-11']);
        $this->assertSame(1, $this->rows($this->hotel, LeaveLedger::class, ['kind' => 'accrual']));
        $this->assertSame(1, $this->rows($this->hotelB, LeaveLedger::class, ['kind' => 'accrual']));
    }

    public function test_year_end_and_expiry_idempotent(): void
    {
        Artisan::call('workforce:leave-year-end', ['--year' => 2025]);
        $this->assertSame(1, $this->rows($this->hotel, LeaveLedger::class, ['kind' => 'carry_over']));
        $this->assertSame(1, $this->rows($this->hotelB, LeaveLedger::class, ['kind' => 'carry_over']));
        Artisan::call('workforce:leave-year-end', ['--year' => 2025]);
        $this->assertSame(1, $this->rows($this->hotel, LeaveLedger::class, ['kind' => 'carry_over']));
        $this->assertSame(0, Artisan::call('workforce:leave-expire'));
        $this->assertSame(0, Artisan::call('workforce:leave-expire'));
    }

    public function test_one_failing_hotel_does_not_stop_the_other(): void
    {
        $this->app->bind(ClockService::class, fn () => new class extends ClockService
        {
            public function __construct() {}

            public function scanMissingClockOut(): int
            {
                if (app(TenantContext::class)->hotelId() === $GLOBALS['j1_fail_hotel']) {
                    throw new \RuntimeException('boom');
                }

                return 0;
            }
        });
        $GLOBALS['j1_fail_hotel'] = $this->hotel->id;
        $this->assertSame(1, Artisan::call('workforce:scan-clock-out'));
        $out = Artisan::output();
        $this->assertStringContainsString('AKW: FAILED', $out);
        $this->assertStringContainsString('YDE: 0 missing', $out);
    }

    public function test_revenue_commands_run_per_hotel(): void
    {
        $this->assertSame(0, Artisan::call('revenue:release-allotments'));
        $out = Artisan::output();
        $this->assertStringContainsString('AKW: 0 room(s) released', $out);
        $this->assertStringContainsString('YDE: 0 room(s) released', $out);
        $this->assertSame(0, Artisan::call('revenue:upsell-pre-arrival'));
        $this->assertStringContainsString('YDE: 0 reservation(s) offered', Artisan::output());
        $this->assertSame(0, Artisan::call('revenue:upsell-pre-arrival', ['--dry-run' => true]));
    }

    public function test_schedule_registry_contains_new_jobs(): void
    {
        $cmds = collect(app(Schedule::class)->events())->mapWithKeys(fn ($e) => [$e->command => $e]);
        $expect = [
            'workforce:scan-clock-out' => '*/30 * * * *', 'workforce:leave-expire' => '10 4 * * *', 'workforce:leave-accrue' => '20 4 1 * *',
            'workforce:leave-year-end' => '30 4 1 1 *', 'revenue:upsell-pre-arrival' => '0 8 * * *', 'revenue:release-allotments' => '40 4 * * *',
        ];
        foreach ($expect as $name => $cron) {
            $e = $cmds->first(fn ($e, $c) => str_contains($c, $name));
            $this->assertNotNull($e, $name);
            $this->assertSame($cron, $e->expression, $name);
            $this->assertTrue($e->withoutOverlapping, $name);
            $this->assertTrue($e->onOneServer, $name);
        }
    }
}
