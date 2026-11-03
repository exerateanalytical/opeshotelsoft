<?php

namespace App\Modules\HR\Talent\Domain;

class Offer extends TalentModel
{
    protected $table = 'tal_offers';

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['start_date' => 'date', 'end_date' => 'date', 'expires_at' => 'datetime', 'decided_at' => 'datetime'];
    }
}
