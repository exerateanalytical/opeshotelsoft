<?php

namespace App\Modules\HR\Talent\Domain;

class JobPost extends TalentModel
{
    protected $table = 'tal_job_posts';

    public function applicants()
    {
        return $this->hasMany(Applicant::class, 'job_post_id');
    }

    protected function casts(): array
    {
        return ['closes_on' => 'date', 'published_at' => 'datetime'];
    }
}
