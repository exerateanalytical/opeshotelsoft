<?php

namespace App\Modules\HR\Domain\Lifecycle;

use App\Modules\HR\Domain\Employee;
use App\Platform\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class EContract extends Model
{
    use BelongsToHotel;

    protected $table = 'hr_econtracts';

    protected $guarded = ['id', 'group_id', 'hotel_id'];

    protected $hidden = ['token_hash', 'otp_hash', 'employee_signature', 'employer_signature', 'pdf_path'];

    protected function casts(): array
    {
        return [
            'token_expires_at' => 'datetime', 'otp_expires_at' => 'datetime', 'otp_verified_at' => 'datetime',
            'employee_signed_at' => 'datetime', 'employer_signed_at' => 'datetime',
        ];
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function audit()
    {
        return $this->hasMany(EContractAudit::class, 'econtract_id')->orderBy('id');
    }
}
