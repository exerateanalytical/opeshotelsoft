<?php

namespace App\Modules\Guests\Domain;

use App\Platform\Tenancy\BelongsToGroup;
use Illuminate\Database\Eloquent\Model;

class CompanyContact extends Model
{
    use BelongsToGroup;

    protected $guarded = ['id', 'group_id'];

    protected function casts(): array
    {
        return ['primary' => 'boolean'];
    }
}
