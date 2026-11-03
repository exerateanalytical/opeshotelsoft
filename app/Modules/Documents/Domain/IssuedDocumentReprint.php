<?php

namespace App\Modules\Documents\Domain;

use Illuminate\Database\Eloquent\Model;

class IssuedDocumentReprint extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['printed_at' => 'datetime'];
    }
}
