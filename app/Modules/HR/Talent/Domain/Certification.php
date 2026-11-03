<?php

namespace App\Modules\HR\Talent\Domain;

class Certification extends TalentModel
{
    protected $table = 'tal_certifications';

    protected $hidden = ['file_path'];

    protected function casts(): array
    {
        return ['issued_on' => 'date', 'expires_on' => 'date', 'last_alert_at' => 'datetime'];
    }
}
