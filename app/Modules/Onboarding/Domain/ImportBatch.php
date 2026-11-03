<?php

namespace App\Modules\Onboarding\Domain;

use App\Platform\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** One spreadsheet import (dry run or real) with its row-by-row validation report. */
class ImportBatch extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'group_id', 'hotel_id'];

    protected function casts(): array
    {
        return ['report' => 'array', 'dry_run' => 'boolean'];
    }
}
