<?php

namespace App\Modules\Channels\Application;

use App\Modules\Channels\Domain\Connection;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/** Pushes ARI for a date range to one connection. De-duplicated while queued. */
class PushAri implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, Queueable;

    public int $uniqueFor = 30;

    public function __construct(public int $connectionId, public string $from, public string $to, public ?array $roomTypeIds = null) {}

    public function uniqueId(): string
    {
        return $this->connectionId.'|'.$this->from.'|'.$this->to.'|'.implode(',', $this->roomTypeIds ?? []);
    }

    public function handle(TenantContext $ctx, AriBuilder $builder, ChannelGateway $gateway): void
    {
        $c = $ctx->withoutScope(fn () => Connection::find($this->connectionId));
        if (! $c || ! $c->isLive()) {
            return;
        }
        $ctx->set($c->group_id, $c->hotel_id, [$c->hotel_id]);
        $rows = $builder->rows($c, $this->from, $this->to, $this->roomTypeIds);
        if ($rows) {
            $gateway->send($c, 'ari', $gateway->driver($c)->ariRequests($c, $rows));
        }
    }
}
