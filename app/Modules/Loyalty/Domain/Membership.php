<?php

namespace App\Modules\Loyalty\Domain;

use App\Modules\Guests\Domain\Guest;
use App\Platform\Tenancy\BelongsToGroup;
use Illuminate\Database\Eloquent\Model;

class Membership extends Model
{
    use BelongsToGroup;

    protected $table = 'loyalty_memberships';

    protected $guarded = ['id', 'group_id'];

    protected function casts(): array
    {
        return ['enrolled_at' => 'datetime', 'tier_reviewed_at' => 'datetime'];
    }

    public function guest()
    {
        return $this->belongsTo(Guest::class);
    }

    public function tier()
    {
        return $this->belongsTo(Tier::class, 'loyalty_tier_id');
    }

    public function cards()
    {
        return $this->hasMany(Card::class, 'loyalty_membership_id');
    }

    public function transactions()
    {
        return $this->hasMany(Transaction::class, 'loyalty_membership_id')->orderByDesc('id');
    }
}
