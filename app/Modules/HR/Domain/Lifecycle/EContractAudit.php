<?php

namespace App\Modules\HR\Domain\Lifecycle;

use Illuminate\Database\Eloquent\Model;

/** Append-only signing trail. */
class EContractAudit extends Model
{
    protected $table = 'hr_econtract_audit';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['meta' => 'array', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => false);
        static::deleting(fn () => false);
    }
}
