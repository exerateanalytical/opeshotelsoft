<?php

namespace App\Modules\HR\Talent\Domain;

class Interview extends TalentModel
{
    protected $table = 'tal_interviews';

    protected function casts(): array
    {
        return ['scheduled_at' => 'datetime', 'panel' => 'array', 'criteria' => 'array'];
    }
}
