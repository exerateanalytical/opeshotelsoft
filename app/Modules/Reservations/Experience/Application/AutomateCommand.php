<?php

namespace App\Modules\Reservations\Experience\Application;

use Illuminate\Console\Command;

class AutomateCommand extends Command
{
    protected $signature = 'experience:automate';

    protected $description = 'Remind and release unpaid deposits, mark overdue arrivals as no-show';

    public function handle(DepositAutomation $automation): int
    {
        $r = $automation->run();
        $this->info("Reminded {$r['reminded']}, released {$r['released']}, no-shows {$r['no_shows']}.");

        return self::SUCCESS;
    }
}
