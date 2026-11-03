<?php

namespace App\Modules\Accounting\Domain;

use Illuminate\Database\Eloquent\Model;

/** Tenancy flows through the bank account (always load lines via a scoped BankAccount). */
class BankStatementLine extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['date' => 'date', 'reconciled_at' => 'datetime'];
    }

    public function bankAccount()
    {
        return $this->belongsTo(BankAccount::class);
    }
}
