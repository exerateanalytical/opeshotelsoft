<?php

namespace App\Modules\HR\Talent\Domain;

class PrivacyLog extends TalentModel
{
    protected $table = 'tal_privacy_log';

    protected function casts(): array
    {
        return ['meta' => 'array'];
    }
}
