<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// A-C hardening: the legacy POST accounting/entries now requires a supporting document unless the hotel
// setting `accounting.attachments_optional` is true. Hotels that exist when this runs keep their previous
// behaviour (optional = true); hotels created later have no key and are therefore strict.
return new class extends Migration
{
    public function up(): void
    {
        DB::table('hotels')->orderBy('id')->get(['id', 'settings'])->each(function ($h) {
            $s = json_decode((string) $h->settings, true) ?: [];
            if (! array_key_exists('accounting.attachments_optional', $s)) {
                $s['accounting.attachments_optional'] = true;
                DB::table('hotels')->where('id', $h->id)->update(['settings' => json_encode($s, JSON_UNESCAPED_UNICODE)]);
            }
        });
    }

    public function down(): void
    {
        // Leaves the setting in place: removing it would silently make existing hotels strict.
    }
};
