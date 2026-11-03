<?php

namespace App\Modules\Channels\Domain;

use App\Platform\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Connection extends Model
{
    use BelongsToHotel;

    public const PROVIDERS = [
        'booking_com' => ['Booking.com', 'direct', 'B.XML / OTA 2003B'],
        'expedia' => ['Expedia Group', 'direct', 'Expedia QuickConnect (EQC)'],
        'tripadvisor' => ['Tripadvisor', 'metasearch', 'TripConnect / Hotel Availability API'],
        'channex' => ['Channex', 'channel_manager', 'Channex REST API'],
        'myallocator' => ['MyAllocator (Cloudbeds)', 'channel_manager', 'MyAllocator JSON API'],
        'cloudbeds' => ['Cloudbeds', 'channel_manager', 'Cloudbeds API v1.2'],
        'siteminder' => ['SiteMinder', 'channel_manager', 'pmsXchange (OTA XML)'],
    ];

    protected $table = 'channel_connections';

    protected $guarded = ['id', 'group_id', 'hotel_id', 'uuid'];

    protected $hidden = ['credentials'];

    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array', 'settings' => 'array',
            'push_rates' => 'boolean', 'push_availability' => 'boolean', 'receive_reservations' => 'boolean',
            'last_push_at' => 'datetime', 'last_pull_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (Connection $c) => $c->uuid ??= (string) Str::uuid());
    }

    public function roomMappings()
    {
        return $this->hasMany(RoomMapping::class, 'channel_connection_id');
    }

    public function rateMappings()
    {
        return $this->hasMany(RateMapping::class, 'channel_connection_id');
    }

    public function isLive(): bool
    {
        return in_array($this->status, ['testing', 'active'], true);
    }

    public function hasCredentials(): bool
    {
        return ! empty($this->credentials) && collect($this->credentials)->filter()->isNotEmpty();
    }
}
