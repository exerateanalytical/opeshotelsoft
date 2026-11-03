<?php

namespace App\Modules\People\Domain;

use App\Platform\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class ReviewCycle extends Model
{
    use BelongsToHotel;

    protected $table = 'people_review_cycles';

    protected $guarded = ['id', 'group_id', 'hotel_id'];

    protected function casts(): array
    {
        return ['period_start' => 'date', 'period_end' => 'date', 'scale' => 'integer'];
    }

    public function reviews()
    {
        return $this->hasMany(Review::class, 'cycle_id');
    }
}
