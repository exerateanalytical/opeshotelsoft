<?php

namespace App\Modules\Loyalty\Domain;

use Illuminate\Database\Eloquent\Model;

class Card extends Model
{
    protected $table = 'loyalty_cards';

    protected $guarded = ['id'];

    protected $hidden = [];

    protected function casts(): array
    {
        return ['issued_at' => 'datetime', 'expires_on' => 'date', 'blocked_at' => 'datetime'];
    }

    public function membership()
    {
        return $this->belongsTo(Membership::class, 'loyalty_membership_id');
    }

    public function isUsable(): bool
    {
        return $this->status === 'active' && (! $this->expires_on || $this->expires_on->toDateString() >= now()->toDateString());
    }
}
