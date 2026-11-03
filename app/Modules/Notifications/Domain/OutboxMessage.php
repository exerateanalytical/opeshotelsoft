<?php

namespace App\Modules\Notifications\Domain;

use App\Platform\Tenancy\BelongsToGroup;
use Illuminate\Database\Eloquent\Model;

/**
 * Group-scoped. Queue workers and scheduled commands run without a tenant,
 * so they read through TenantContext::withoutScope explicitly.
 */
class OutboxMessage extends Model
{
    use BelongsToGroup;

    protected $table = 'notification_outbox';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['next_attempt_at' => 'datetime', 'sent_at' => 'datetime'];
    }
}
