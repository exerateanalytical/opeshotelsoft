<?php

namespace App\Modules\People\Domain;

use App\Platform\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class Task extends Model
{
    use BelongsToHotel;

    protected $table = 'people_tasks';

    protected $guarded = ['id', 'group_id', 'hotel_id'];

    protected function casts(): array
    {
        return ['checklist' => 'array', 'requires_photo' => 'boolean', 'due_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    public function photos()
    {
        return $this->hasMany(TaskPhoto::class, 'task_id');
    }

    public function isOverdue(): bool
    {
        return $this->due_at !== null && $this->due_at->isPast() && in_array($this->status, ['open', 'in_progress'], true);
    }
}
