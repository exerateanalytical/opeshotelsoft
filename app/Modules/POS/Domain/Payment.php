<?php

namespace App\Modules\POS\Domain;

use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    protected $table = 'pos_payments';

    protected $guarded = ['id'];
}
