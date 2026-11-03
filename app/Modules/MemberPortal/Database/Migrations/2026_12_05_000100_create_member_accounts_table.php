<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Customer (loyalty-club member) sign-in. Completely separate from `users` (staff).
        Schema::create('member_accounts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('group_id')->constrained();
            $t->foreignId('guest_id')->nullable()->constrained(); // linked only after the contact is verified
            $t->string('first_name', 100);
            $t->string('last_name', 100);
            $t->string('email')->nullable();   // lower case
            $t->string('phone', 32)->nullable(); // digits only
            $t->string('verified_contact', 8)->nullable(); // email | phone: the contact proven by a one-time code
            $t->string('password')->nullable();
            $t->string('locale', 5)->default('fr');
            $t->boolean('marketing_consent')->default(false); // copied to the guest profile when the account is linked
            $t->timestamp('verified_at')->nullable();
            // One-time code (sign-up, sign-in, password reset). Only a keyed hash is stored.
            $t->string('otp_hash', 64)->nullable();
            $t->string('otp_purpose', 12)->nullable();
            $t->timestamp('otp_expires_at')->nullable();
            $t->unsignedSmallInteger('otp_attempts')->default(0);
            $t->timestamp('otp_sent_at')->nullable();
            $t->unsignedSmallInteger('failed_attempts')->default(0);
            $t->timestamp('locked_until')->nullable();
            $t->timestamp('last_login_at')->nullable();
            $t->rememberToken();
            $t->timestamps();
            $t->unique(['group_id', 'guest_id']);
        });
        DB::statement('CREATE UNIQUE INDEX member_accounts_group_email_unique ON member_accounts (group_id, email) WHERE email IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX member_accounts_group_phone_unique ON member_accounts (group_id, phone) WHERE phone IS NOT NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('member_accounts');
    }
};
