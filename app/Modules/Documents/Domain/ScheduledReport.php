<?php

namespace App\Modules\Documents\Domain;

use App\Platform\Tenancy\BelongsToHotel;
use Cron\CronExpression;
use Illuminate\Database\Eloquent\Model;

class ScheduledReport extends Model
{
    use BelongsToHotel;

    protected $fillable = ['name', 'report', 'params', 'format', 'recipients', 'frequency', 'active', 'created_by', 'next_run_at'];

    protected $casts = [
        'params' => 'array', 'recipients' => 'array', 'active' => 'boolean',
        'next_run_at' => 'datetime', 'last_run_at' => 'datetime',
    ];

    public function computeNextRun(): void
    {
        $this->next_run_at = (new CronExpression($this->frequency))->getNextRunDate(now())->format('Y-m-d H:i:s');
    }
}
