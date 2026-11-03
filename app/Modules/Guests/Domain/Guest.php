<?php

namespace App\Modules\Guests\Domain;

use App\Platform\Tenancy\BelongsToGroup;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Guest extends Model
{
    use BelongsToGroup, LogsActivity, SoftDeletes;

    protected $guarded = ['id', 'group_id', 'merged_into_id'];

    protected $appends = ['full_name'];

    protected function casts(): array
    {
        return [
            'preferences' => 'array',
            'vip' => 'boolean',
            'blacklisted' => 'boolean',
            'marketing_consent' => 'boolean',
            'date_of_birth' => 'date',
            'id_expiry' => 'date',
            'consent_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function getFullNameAttribute(): string
    {
        return trim(($this->title ? $this->title.' ' : '').$this->first_name.' '.$this->last_name);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logAll()->logOnlyDirty();
    }
}
