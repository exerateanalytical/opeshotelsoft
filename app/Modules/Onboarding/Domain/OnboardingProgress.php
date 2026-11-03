<?php

namespace App\Modules\Onboarding\Domain;

use App\Platform\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** Where a hotel is in the setup wizard, so it can be resumed. */
class OnboardingProgress extends Model
{
    use BelongsToHotel;

    protected $table = 'onboarding_progress';

    protected $guarded = ['id', 'group_id', 'hotel_id'];

    protected function casts(): array
    {
        return ['completed' => 'array', 'data' => 'array', 'went_live_at' => 'datetime'];
    }
}
