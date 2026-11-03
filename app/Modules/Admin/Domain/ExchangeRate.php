<?php

namespace App\Modules\Admin\Domain;

use Illuminate\Database\Eloquent\Model;

/** One unit of `from` = `rate` units of `to` (always the functional currency here) on `date`. */
class ExchangeRate extends Model
{
    public $timestamps = false;

    protected $fillable = ['from', 'to', 'date', 'rate'];

    protected $casts = ['date' => 'date:Y-m-d', 'rate' => 'float'];

    /** Latest rate on or before a date, or null. 1.0 for the functional currency. */
    public static function rateOn(string $from, string $date): ?float
    {
        if (strtoupper($from) === Currency::FUNCTIONAL) {
            return 1.0;
        }

        return static::where('from', strtoupper($from))->where('to', Currency::FUNCTIONAL)
            ->where('date', '<=', $date)->orderByDesc('date')->value('rate');
    }
}
