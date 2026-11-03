<?php

namespace App\Modules\HR\Talent\Domain;

use App\Platform\Tenancy\BelongsToGroup;
use Illuminate\Database\Eloquent\Model;

/** Base for group-wide catalogue rows (skills, certificate types, courses). */
abstract class GroupModel extends Model
{
    use BelongsToGroup;

    protected $guarded = ['id', 'group_id'];
}