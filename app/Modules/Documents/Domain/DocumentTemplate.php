<?php

namespace App\Modules\Documents\Domain;

use App\Platform\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** A hotel's customised text for one document kind and language. Rendered by TemplateRenderer only. */
class DocumentTemplate extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'group_id', 'hotel_id'];
}
