<?php

namespace App\Modules\Stock\Control\Domain;

use App\Platform\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class UsageVarianceReview extends Model
{
    use BelongsToHotel;

    protected $table = 'usage_variance_reviews';

    protected $guarded = ['id', 'group_id', 'hotel_id'];

    protected function casts(): array
    {
        return ['period_from' => 'date', 'period_to' => 'date'];
    }

    public function lines()
    {
        return $this->hasMany(UsageVarianceLine::class);
    }
}
