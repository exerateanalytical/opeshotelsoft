<?php

namespace App\Modules\People\Domain;

use App\Platform\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class Review extends Model
{
    use BelongsToHotel;

    protected $table = 'people_reviews';

    protected $guarded = ['id', 'group_id', 'hotel_id'];

    protected function casts(): array
    {
        return ['self_rating' => 'float', 'manager_rating' => 'float', 'calibrated_rating' => 'float', 'self_submitted_at' => 'datetime', 'manager_submitted_at' => 'datetime'];
    }

    public function cycle()
    {
        return $this->belongsTo(ReviewCycle::class, 'cycle_id');
    }

    public function objectives()
    {
        return $this->hasMany(Objective::class, 'review_id')->orderBy('id');
    }

    /** Rating used for reporting: calibrated if set, else manager, else self. */
    public function finalRating(): ?float
    {
        return $this->calibrated_rating ?? $this->manager_rating;
    }
}
