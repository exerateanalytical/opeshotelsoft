<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// R-B digital check-in: ID details, ID photo, signature and registration card acceptance.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('guest_portal_checkins', function (Blueprint $t) {
            $t->date('id_expiry')->nullable();
            $t->string('id_country', 2)->nullable();
            $t->date('birth_date')->nullable();
            $t->string('address')->nullable();
            $t->string('id_photo_path')->nullable();
            $t->text('signature')->nullable(); // PNG data URL, drawn on the phone
            $t->timestamp('signed_at')->nullable();
            $t->boolean('card_accepted')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('guest_portal_checkins', fn (Blueprint $t) => $t->dropColumn(['id_expiry', 'id_country', 'birth_date', 'address', 'id_photo_path', 'signature', 'signed_at', 'card_accepted']));
    }
};
