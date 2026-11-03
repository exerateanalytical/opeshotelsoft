<?php

namespace App\Modules\HR\Talent\Domain;

class ChangeRequest extends TalentModel
{
    protected $table = 'tal_requests';

    protected function casts(): array
    {
        return ['payload' => 'array', 'decided_at' => 'datetime'];
    }
}
