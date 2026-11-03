<?php

namespace App\Modules\Guests\Domain;

use App\Platform\Tenancy\BelongsToGroup;
use Illuminate\Database\Eloquent\Model;

class Company extends Model
{
    use BelongsToGroup;

    protected $guarded = ['id', 'group_id'];

    protected function casts(): array
    {
        return ['city_ledger' => 'boolean', 'active' => 'boolean', 'commission_percent' => 'decimal:2'];
    }
}
