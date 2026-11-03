<?php

namespace App\Modules\Guests\Domain;

use App\Models\User;
use App\Platform\Tenancy\BelongsToGroup;
use Illuminate\Database\Eloquent\Model;

class PrivacyRequest extends Model
{
    use BelongsToGroup;

    protected $table = 'guest_privacy_requests';

    protected $guarded = ['id', 'group_id'];

    protected function casts(): array
    {
        return ['decided_at' => 'datetime'];
    }

    public function guest()
    {
        return $this->belongsTo(Guest::class)->withTrashed();
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decider()
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
