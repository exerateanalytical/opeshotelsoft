<?php

namespace App\Modules\Loyalty\Domain;

use App\Platform\Tenancy\BelongsToGroup;
use Illuminate\Database\Eloquent\Model;

class Program extends Model
{
    use BelongsToGroup;

    protected $table = 'loyalty_programs';

    protected $guarded = ['id', 'group_id'];

    protected function casts(): array
    {
        return ['eligible_departments' => 'array', 'active' => 'boolean'];
    }

    public function tiers()
    {
        return $this->hasMany(Tier::class, 'loyalty_program_id')->orderBy('rank');
    }
}
