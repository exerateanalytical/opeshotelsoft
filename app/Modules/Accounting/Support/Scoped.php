<?php

namespace App\Modules\Accounting\Support;

use App\Modules\Accounting\Domain\Account;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/** Tenant helpers shared by the A-B services (query-builder tables carry group_id and hotel_id explicitly). */
trait Scoped
{
    private TenantContext $ctx;

    protected function hotelId(): int
    {
        return $this->ctx->requireHotelId();
    }

    /** @return array{group_id: ?int, hotel_id: int} */
    protected function tenant(): array
    {
        return ['group_id' => $this->ctx->groupId(), 'hotel_id' => $this->ctx->requireHotelId()];
    }

    protected function actorId(): ?int
    {
        return $this->ctx->actor()?->id;
    }

    protected function table(string $name)
    {
        return DB::table($name)->where("$name.hotel_id", $this->ctx->requireHotelId());
    }

    protected function stamp(): array
    {
        return ['created_at' => now(), 'updated_at' => now()];
    }

    /** Create accounts that A-B postings need and the base chart does not carry (per hotel, idempotent). */
    protected function ensureAccounts(array $accounts): void
    {
        foreach ($accounts as $code => [$name, $class, $type]) {
            Account::firstOrCreate(['code' => (string) $code], ['name' => $name, 'class' => $class, 'type' => $type]);
        }
    }
}
