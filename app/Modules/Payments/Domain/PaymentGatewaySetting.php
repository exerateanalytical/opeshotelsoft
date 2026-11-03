<?php

namespace App\Modules\Payments\Domain;

use App\Platform\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** Per-hotel gateway settings. Credentials are encrypted at rest and never serialised. */
class PaymentGatewaySetting extends Model
{
    use BelongsToHotel;

    protected $table = 'payment_gateways';

    protected $guarded = ['id', 'group_id', 'hotel_id'];

    protected $hidden = ['credentials'];

    public const PROVIDERS = ['mtn_momo', 'orange_money', 'card'];

    /** Credential fields per provider; secret ones are masked in responses. */
    public const FIELDS = [
        'mtn_momo' => ['base_url' => false, 'subscription_key' => true, 'api_user' => false, 'api_key' => true, 'target_environment' => false, 'callback_secret' => true],
        'orange_money' => ['base_url' => false, 'client_id' => false, 'client_secret' => true, 'merchant_key' => true],
        'card' => ['base_url' => false, 'site_id' => false, 'api_key' => true, 'secret_key' => true],
    ];

    protected function casts(): array
    {
        return ['credentials' => 'encrypted:array', 'enabled' => 'boolean'];
    }

    public function hasCredentials(): bool
    {
        return ! empty(array_filter($this->credentials ?? []));
    }

    public function masked(): array
    {
        $out = [];
        foreach (self::FIELDS[$this->provider] ?? [] as $field => $secret) {
            $v = (string) ($this->credentials[$field] ?? '');
            $out[$field] = $v === '' ? null : ($secret ? str_repeat('•', 6).substr($v, -4) : $v);
        }

        return $out;
    }
}
