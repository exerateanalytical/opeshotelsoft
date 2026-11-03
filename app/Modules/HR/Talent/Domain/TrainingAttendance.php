<?php

namespace App\Modules\HR\Talent\Domain;

class TrainingAttendance extends TalentModel
{
    protected $table = 'tal_training_attendances';

    protected function casts(): array
    {
        return ['applied' => 'boolean', 'score' => 'float'];
    }
}
