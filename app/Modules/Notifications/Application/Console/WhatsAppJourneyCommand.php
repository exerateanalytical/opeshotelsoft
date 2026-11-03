<?php

namespace App\Modules\Notifications\Application\Console;

use App\Modules\Notifications\Application\WhatsApp\GuestJourney;
use Illuminate\Console\Command;

/** Daily WhatsApp journey steps (pre-arrival check-in link, bill + pay link on departure day). Idempotent. */
class WhatsAppJourneyCommand extends Command
{
    protected $signature = 'whatsapp:journey';

    protected $description = 'Send scheduled WhatsApp guest journey messages';

    public function handle(GuestJourney $journey): int
    {
        $this->info('Processed '.$journey->daily().' reservation(s).');

        return self::SUCCESS;
    }
}
