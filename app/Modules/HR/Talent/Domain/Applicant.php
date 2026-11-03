<?php

namespace App\Modules\HR\Talent\Domain;

class Applicant extends TalentModel
{
    protected $table = 'tal_applicants';

    public function notes()
    {
        return $this->hasMany(ApplicantNote::class, 'applicant_id');
    }

    protected $hidden = ['cv_path'];

    protected function casts(): array
    {
        return ['talent_pool' => 'boolean', 'consent_at' => 'datetime', 'retain_until' => 'date', 'anonymised_at' => 'datetime'];
    }
}
