<?php

namespace App\Models;

use App\Modules\Tenancy\Domain\Group;
use App\Modules\Tenancy\Domain\Hotel;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Passport\Contracts\OAuthenticatable;
use Laravel\Passport\HasApiTokens;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements OAuthenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, LogsActivity, Notifiable;

    protected $guard_name = 'api';

    protected $fillable = ['name', 'email', 'password', 'group_id', 'all_hotels', 'locale', 'phone', 'status', 'is_platform_admin'];

    protected $hidden = ['password', 'remember_token', 'pos_pin', 'mfa_secret', 'mfa_recovery_codes', 'mfa_last_step'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'mfa_confirmed_at' => 'datetime',
            'password' => 'hashed',
            'pos_pin' => 'hashed',
            'mfa_secret' => 'encrypted',
            'all_hotels' => 'boolean',
            'is_platform_admin' => 'boolean',
        ];
    }

    public function group()
    {
        return $this->belongsTo(Group::class);
    }

    public function hotels()
    {
        return $this->belongsToMany(Hotel::class);
    }

    /** Hotels this user may act on; null means every hotel in the group. */
    public function allowedHotelIds(): ?array
    {
        if ($this->all_hotels) {
            return null;
        }

        return $this->hotels()->withoutGlobalScopes()->pluck('hotels.id')->map(fn ($id) => (int) $id)->all();
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['name', 'email', 'status', 'all_hotels'])->logOnlyDirty();
    }
}
