<?php

namespace App\Modules\HR\Talent\Console;

use App\Modules\HR\Talent\Application\RecruitmentService;
use App\Modules\HR\Talent\Application\SkillsService;
use App\Modules\Tenancy\Domain\Hotel;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Console\Command;

class TalentCommands
{
    /** @return array<class-string> */
    public static function all(): array
    {
        return [CertificateScanCommand::class, ApplicantRetentionCommand::class];
    }
}

/** Send expiry alerts for staff certificates (food safety, first aid, fire safety). */
class CertificateScanCommand extends Command
{
    protected $signature = 'talent:scan-certificates';

    protected $description = 'Send expiry alerts for staff certificates';

    public function handle(SkillsService $skills): int
    {
        $this->info('Alerts raised: '.$skills->scanAllHotels());

        return self::SUCCESS;
    }
}

/** Anonymise applicants whose retention period has ended. */
class ApplicantRetentionCommand extends Command
{
    protected $signature = 'talent:purge-applicants';

    protected $description = 'Anonymise applicants whose retention period has ended';

    public function handle(RecruitmentService $rec, TenantContext $ctx): int
    {
        $n = 0;
        foreach ($ctx->withoutScope(fn () => Hotel::withoutGlobalScopes()->get()) as $h) {
            $ctx->forHotel($h);
            $n += $rec->purgeExpired();
        }
        $this->info("Anonymised: $n");

        return self::SUCCESS;
    }
}
