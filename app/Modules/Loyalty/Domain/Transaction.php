<?php

namespace App\Modules\Loyalty\Domain;

use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
    protected $table = 'loyalty_transactions';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['expires_on' => 'date'];
    }
}
