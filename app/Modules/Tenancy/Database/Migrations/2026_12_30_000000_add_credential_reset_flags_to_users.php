<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admin credential resets (GAPH): a user given a temporary password must choose a new one at next sign-in,
 * and a user whose two-factor was reset must enrol again, whatever their role.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->boolean('must_change_password')->default(false);
            $t->boolean('must_enrol_mfa')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['must_change_password', 'must_enrol_mfa']));
    }
};
