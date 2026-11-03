<?php

namespace App\Modules\HR\Domain;

use App\Platform\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class OnboardingTemplate extends Model
{
    use BelongsToHotel;

    protected $table = 'onboarding_templates';

    protected $guarded = ['id', 'group_id', 'hotel_id'];

    protected function casts(): array
    {
        return ['tasks' => 'array'];
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }
}
