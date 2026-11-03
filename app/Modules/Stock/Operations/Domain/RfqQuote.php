<?php

namespace App\Modules\Stock\Operations\Domain;

use Illuminate\Database\Eloquent\Model;

class RfqQuote extends Model
{
    protected $table = 'rfq_quotes';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['valid_until' => 'date'];
    }
}
