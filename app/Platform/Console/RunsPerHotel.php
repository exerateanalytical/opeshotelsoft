<?php

namespace App\Platform\Console;

use App\Modules\Tenancy\Domain\Hotel;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Shared loop for scheduled commands: every active hotel in its own tenant context,
 * one line per hotel, a failing hotel never stops the others (exit code 1 if any failed).
 * Commands using it declare {--hotel=} and optionally {--dry-run} (compute, then roll back).
 */
trait RunsPerHotel
{
    /** @param callable(Hotel): string $job returns the summary text for the hotel */
    protected function eachActiveHotel(callable $job, bool $rollbackOnDryRun = true): int
    {
        $ctx = app(TenantContext::class);
        $saved = [$ctx->groupId(), $ctx->hotelId(), $ctx->allowedHotelIds(), $ctx->actor()];
        $hotels = $ctx->withoutScope(fn () => Hotel::where('status', 'active')
            ->when($this->option('hotel'), fn ($q, $id) => $q->where('id', $id))->orderBy('id')->get());
        $dry = $this->hasOption('dry-run') && $this->option('dry-run');
        $failed = 0;
        foreach ($hotels as $hotel) {
            $ctx->set($hotel->group_id, $hotel->id, [$hotel->id], null);
            try {
                if ($dry && $rollbackOnDryRun) {
                    DB::beginTransaction();
                    try {
                        $line = $job($hotel);
                    } finally {
                        DB::rollBack();
                    }
                } else {
                    $line = $job($hotel);
                }
                $this->line("{$hotel->code}: $line".($dry ? ' (dry run)' : ''));
            } catch (Throwable $e) {
                $failed++;
                report($e);
                $this->error("{$hotel->code}: FAILED - ".$e->getMessage());
            }
        }
        $ctx->set(...$saved);

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
