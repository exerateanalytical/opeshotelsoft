<?php

namespace App\Modules\ServiceRequests\Domain;

use App\Platform\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class ServiceRequest extends Model
{
    use BelongsToHotel;

    public const OPEN = ['new', 'acknowledged', 'in_progress'];

    protected $guarded = ['id', 'group_id', 'hotel_id'];

    protected function casts(): array
    {
        return [
            'items' => 'array', 'due_at' => 'datetime', 'acknowledged_at' => 'datetime', 'started_at' => 'datetime',
            'completed_at' => 'datetime', 'cancelled_at' => 'datetime', 'escalated_at' => 'datetime', 'overdue_at' => 'datetime',
        ];
    }

    public function assignee()
    {
        return $this->belongsTo(\App\Models\User::class, 'assigned_to')->select(['id', 'name']);
    }
}
