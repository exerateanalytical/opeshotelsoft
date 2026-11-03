<?php

namespace App\Modules\Admin\Domain;

use Illuminate\Database\Eloquent\Model;

/** Global currency catalogue; exactly one functional currency (XAF). */
class Currency extends Model
{
    public const FUNCTIONAL = 'XAF';

    protected $primaryKey = 'code';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['code', 'name', 'symbol', 'decimals', 'active'];

    protected $casts = ['is_functional' => 'boolean', 'active' => 'boolean', 'decimals' => 'integer'];
}
