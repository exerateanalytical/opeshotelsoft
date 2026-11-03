<?php

namespace App\Modules\MemberPortal\Domain;

use App\Modules\Guests\Domain\Guest;
use App\Modules\Loyalty\Domain\Membership;
use App\Platform\Tenancy\TenantContext;
use App\Platform\Tenancy\TenantException;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Auth\Authenticatable;

/**
 * A loyalty-club member's sign-in (customer portal, guard `member`). Never a staff `User`.
 *
 * Group scoped whenever a group is in the tenant context. Before sign-in no group is known (the session guard loads
 * the account by id; login looks it up by contact), so the scope only applies when a group is set.
 */
class MemberAccount extends Model implements AuthenticatableContract
{
    use Authenticatable;

    protected $guarded = ['id', 'group_id'];

    protected $hidden = ['password', 'otp_hash', 'remember_token'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed', 'verified_at' => 'datetime', 'otp_expires_at' => 'datetime', 'otp_sent_at' => 'datetime',
            'locked_until' => 'datetime', 'last_login_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope('group', function (Builder $builder) {
            $ctx = app(TenantContext::class);
            if (! $ctx->isBypassed() && $ctx->groupId()) {
                $builder->where($builder->getModel()->qualifyColumn('group_id'), $ctx->groupId());
            }
        });

        static::creating(function (self $model) {
            $model->group_id ??= app(TenantContext::class)->groupId();
            if (! $model->group_id) {
                throw new TenantException('No group in context for '.static::class);
            }
        });
    }

    public function guest()
    {
        return $this->belongsTo(Guest::class);
    }

    public function membership(): ?Membership
    {
        return $this->guest_id ? Membership::where('guest_id', $this->guest_id)->first() : null;
    }

    public function isLocked(): bool
    {
        return $this->locked_until !== null && $this->locked_until->isFuture();
    }
}
