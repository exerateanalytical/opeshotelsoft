<?php

namespace App\Modules\HR\Talent\Domain;

class PayrollReview extends TalentModel
{
    protected $table = 'tal_payroll_reviews';

    protected function casts(): array
    {
        return ['checks' => 'array', 'acknowledged' => 'array', 'reviewed_at' => 'datetime'];
    }
}
