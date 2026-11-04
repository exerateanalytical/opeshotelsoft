<?php

namespace App\Modules\HR\Domain;

use App\Platform\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class Employee extends Model
{
    use BelongsToHotel;

    protected $table = 'employees';

    protected $guarded = ['id', 'group_id', 'hotel_id'];

    /** Pay and identity numbers only serialised for hr.manage / payroll.manage holders and the employee themself. */
    public const SENSITIVE = ['bank_account', 'momo_number', 'cnps_number', 'tax_number'];

    /**
     * SEC2: API responses (toArray / JSON) drop the bank, Mobile Money, CNPS and tax numbers when the signed-in actor
     * lacks hr.manage and payroll.manage and is not the employee. Code reading the attributes directly (payroll,
     * documents, payouts) is unaffected; without an actor (console, queue) nothing is hidden.
     */
    public function toArray()
    {
        $data = parent::toArray();
        $actor = app(\App\Platform\Tenancy\TenantContext::class)->actor() ?? auth()->user();
        if ($actor && ! $actor->can('hr.manage') && ! $actor->can('payroll.manage') && (int) $this->user_id !== (int) $actor->id) {
            foreach (self::SENSITIVE as $k) {
                unset($data[$k]);
            }
        }

        return $data;
    }

    protected function casts(): array
    {
        return ['date_of_birth' => 'date', 'hire_date' => 'date', 'termination_date' => 'date', 'allowances' => 'array', 'leave_days_per_month' => 'float'];
    }

    protected $appends = ['full_name', 'photo_url'];

    public function getFullNameAttribute(): string
    {
        return $this->first_name.' '.$this->last_name;
    }

    /** Web address of the profile photo, or null. */
    public function getPhotoUrlAttribute(): ?string
    {
        $p = $this->photo_path;
        if (! $p) {
            return null;
        }

        return str_starts_with($p, '/') || str_starts_with($p, 'http') ? $p : \Illuminate\Support\Facades\Storage::disk('public')->url($p);
    }

    /** The photo inlined as a data URI, for PDFs. */
    public function photoDataUri(): ?string
    {
        $p = $this->photo_path;
        if (! $p) {
            return null;
        }
        $file = str_starts_with($p, '/') ? public_path(ltrim($p, '/')) : \Illuminate\Support\Facades\Storage::disk('public')->path($p);
        if (! is_file($file)) {
            return null;
        }
        $mime = str_ends_with(strtolower($file), '.png') ? 'image/png' : 'image/jpeg';

        return 'data:'.$mime.';base64,'.base64_encode((string) file_get_contents($file));
    }

    public function contracts()
    {
        return $this->hasMany(Contract::class)->orderByDesc('start_date');
    }

    public function manager()
    {
        return $this->belongsTo(Employee::class, 'reports_to');
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }
}
