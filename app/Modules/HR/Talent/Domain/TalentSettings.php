<?php

namespace App\Modules\HR\Talent\Domain;

class TalentSettings extends TalentModel
{
    protected $table = 'tal_settings';

    protected $attributes = ['payroll_review_required' => false, 'variance_pct' => 20, 'overtime_multiple' => 3.0, 'applicant_retention_months' => 12, 'pool_retention_months' => 24, 'leaver_retention_months' => 60];

    protected function casts(): array
    {
        return ['payroll_review_required' => 'boolean', 'overtime_multiple' => 'float'];
    }
}
