<?php

namespace App\Modules\HR\Talent\Domain;

class TrainingSession extends TalentModel
{
    protected $table = 'tal_training_sessions';

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date'];
    }
}
