<?php

namespace App\Modules\HR\Workforce\Console;

use App\Modules\HR\Workforce\Application\ClockService;
use App\Modules\HR\Workforce\Application\LeavePolicyService;
use App\Platform\Console\RunsPerHotel;
use Illuminate\Console\Command;

class WorkforceCommands
{
    /** @return array<class-string> */
    public static function all(): array
    {
        return [ClockOutScanCommand::class, LeaveAccrueCommand::class, LeaveYearEndCommand::class, LeaveExpireCommand::class];
    }
}

class ClockOutScanCommand extends Command
{
    use RunsPerHotel;

    protected $signature = 'workforce:scan-clock-out {--hotel= : Hotel id} {--dry-run : Compute then roll back}';

    protected $description = 'Flag attendances with a missing clock-out';

    public function handle(ClockService $clock): int
    {
        return $this->eachActiveHotel(fn ($h) => $clock->scanMissingClockOut().' missing clock-out(s) flagged');
    }
}

class LeaveAccrueCommand extends Command
{
    use RunsPerHotel;

    protected $signature = 'workforce:leave-accrue {--hotel= : Hotel id} {--month= : YYYY-MM (default: current month)} {--dry-run : Compute then roll back}';

    protected $description = 'Accrue monthly leave for every employee (idempotent per month)';

    public function handle(LeavePolicyService $leave): int
    {
        $month = $this->option('month') ?: now()->format('Y-m');

        return $this->eachActiveHotel(fn ($h) => "$month: ".$leave->accrue($month)['entries'].' accrual entr(ies)');
    }
}

class LeaveYearEndCommand extends Command
{
    use RunsPerHotel;

    protected $signature = 'workforce:leave-year-end {--hotel= : Hotel id} {--year= : Year to close (default: previous year)} {--dry-run : Compute then roll back}';

    protected $description = 'Close the leave year: carry over up to the cap, lapse the rest (idempotent)';

    public function handle(LeavePolicyService $leave): int
    {
        $year = (int) ($this->option('year') ?: now()->year - 1);

        return $this->eachActiveHotel(function ($h) use ($leave, $year) {
            $r = $leave->yearEnd($year);

            return "$year: carried {$r['carried_days']} day(s), lapsed {$r['lapsed_days']} day(s)";
        });
    }
}

class LeaveExpireCommand extends Command
{
    use RunsPerHotel;

    protected $signature = 'workforce:leave-expire {--hotel= : Hotel id} {--as-of= : Date (default: today)} {--dry-run : Compute then roll back}';

    protected $description = 'Lapse carried-over leave days past their expiry date (idempotent)';

    public function handle(LeavePolicyService $leave): int
    {
        return $this->eachActiveHotel(fn ($h) => $leave->expireCarried($this->option('as-of') ?: null).' carry-over(s) expired');
    }
}
