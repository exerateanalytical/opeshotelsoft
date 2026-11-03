<?php

namespace App\Modules\HR\Domain\Lifecycle;

use App\Platform\Tenancy\BelongsToGroup;
use Illuminate\Database\Eloquent\Model;

class ContractTemplate extends Model
{
    use BelongsToGroup;

    protected $table = 'hr_contract_templates';

    protected $guarded = ['id', 'group_id'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}
