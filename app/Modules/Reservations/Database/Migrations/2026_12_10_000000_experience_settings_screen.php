<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// S2: switches for early check-in / late check-out offers, no-show fee (default off), English name of guest services.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('experience_settings', function (Blueprint $t) {
            $t->boolean('early_checkin_enabled')->default(true);
            $t->boolean('late_checkout_enabled')->default(true);
            $t->boolean('no_show_fee')->default(false);
        });
        Schema::table('experience_services', function (Blueprint $t) {
            $t->string('name_en')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('experience_services', fn (Blueprint $t) => $t->dropColumn('name_en'));
        Schema::table('experience_settings', fn (Blueprint $t) => $t->dropColumn(['early_checkin_enabled', 'late_checkout_enabled', 'no_show_fee']));
    }
};
