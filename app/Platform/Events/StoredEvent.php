<?php

namespace App\Platform\Events;

use Illuminate\Database\Eloquent\Model;

class StoredEvent extends Model
{
    protected $table = 'domain_events';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'occurred_at' => 'datetime',
            'dispatched_at' => 'datetime',
        ];
    }

    /** Envelope published to webhooks and partners (see docs/api/events.yaml). */
    public function envelope(): array
    {
        return [
            'id' => $this->uuid,
            'type' => $this->type,
            'version' => $this->version,
            'occurred_at' => $this->occurred_at?->toIso8601String(),
            'group_id' => $this->group_id,
            'hotel_id' => $this->hotel_id,
            'aggregate' => ['type' => $this->aggregate_type, 'id' => $this->aggregate_id],
            'correlation_id' => $this->correlation_id,
            'data' => $this->payload,
        ];
    }
}
