<?php

namespace App\Modules\Tenancy\Domain;

use Illuminate\Database\Eloquent\Model;

/** A hotel group (tenant). Groups never see each other's data. */
class Group extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['settings' => 'array'];
    }

    public function hotels()
    {
        return $this->hasMany(Hotel::class)->withoutGlobalScopes();
    }
}
