<?php

namespace App\Modules\HR\Talent\Domain;

class InterviewScore extends TalentModel
{
    protected $table = 'tal_interview_scores';

    protected function casts(): array
    {
        return ['scores' => 'array', 'average' => 'float'];
    }
}
