<?php

namespace App\Modules\Platform\Domain;

use App\Platform\Tenancy\BelongsToGroup;
use Illuminate\Database\Eloquent\Model;

class WebhookSubscription extends Model
{
    use BelongsToGroup;

    protected $guarded = ['id', 'group_id'];

    protected $hidden = ['secret'];

    protected function casts(): array
    {
        return ['event_types' => 'array', 'active' => 'boolean'];
    }

    public function wants(string $type): bool
    {
        foreach ($this->event_types as $pattern) {
            if ($pattern === '*' || $pattern === $type || (str_ends_with($pattern, '.*') && str_starts_with($type, substr($pattern, 0, -1)))) {
                return true;
            }
        }

        return false;
    }
}
