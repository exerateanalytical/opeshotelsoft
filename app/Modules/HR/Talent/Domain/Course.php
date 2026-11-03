<?php

namespace App\Modules\HR\Talent\Domain;

class Course extends GroupModel
{
    protected $table = 'tal_courses';

    protected function casts(): array
    {
        return ['active' => 'boolean', 'duration_hours' => 'float'];
    }
}
