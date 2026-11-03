<?php

namespace App\Modules\HR\Domain;

use Illuminate\Database\Eloquent\Model;

/** Country payroll parameters; platform-wide reference data. */
class RulePack extends Model
{
    protected $table = 'payroll_rule_packs';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['params' => 'array', 'certified' => 'boolean', 'effective_from' => 'date', 'certified_at' => 'datetime'];
    }
}
