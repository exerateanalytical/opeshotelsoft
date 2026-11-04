<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * SEC2 security fixes (additive only):
 * - payroll_runs.computed_by: last user who computed the run (four-eyes check on approval);
 * - users.mfa_last_step: last accepted TOTP time step (a code cannot be replayed);
 * - loyalty_programs.max_manual_adjustment: points one manual adjustment may move without loyalty.adjust_large;
 * - channel_connections.inbound_secret: generated HMAC key for inbound bookings; existing connections get one.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('payroll_runs') && ! Schema::hasColumn('payroll_runs', 'computed_by')) {
            Schema::table('payroll_runs', fn (Blueprint $t) => $t->foreignId('computed_by')->nullable()->constrained('users'));
        }
        if (! Schema::hasColumn('users', 'mfa_last_step')) {
            Schema::table('users', fn (Blueprint $t) => $t->unsignedBigInteger('mfa_last_step')->nullable());
        }
        if (Schema::hasTable('loyalty_programs') && ! Schema::hasColumn('loyalty_programs', 'max_manual_adjustment')) {
            Schema::table('loyalty_programs', fn (Blueprint $t) => $t->unsignedInteger('max_manual_adjustment')->default(5000));
        }
        if (Schema::hasTable('channel_connections') && ! Schema::hasColumn('channel_connections', 'inbound_secret')) {
            Schema::table('channel_connections', fn (Blueprint $t) => $t->text('inbound_secret')->nullable());
            foreach (DB::table('channel_connections')->whereNull('inbound_secret')->pluck('id') as $id) {
                DB::table('channel_connections')->where('id', $id)
                    ->update(['inbound_secret' => Crypt::encryptString('chsec_'.Str::random(40))]);
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('channel_connections', 'inbound_secret')) {
            Schema::table('channel_connections', fn (Blueprint $t) => $t->dropColumn('inbound_secret'));
        }
        if (Schema::hasColumn('loyalty_programs', 'max_manual_adjustment')) {
            Schema::table('loyalty_programs', fn (Blueprint $t) => $t->dropColumn('max_manual_adjustment'));
        }
        if (Schema::hasColumn('users', 'mfa_last_step')) {
            Schema::table('users', fn (Blueprint $t) => $t->dropColumn('mfa_last_step'));
        }
        if (Schema::hasColumn('payroll_runs', 'computed_by')) {
            Schema::table('payroll_runs', fn (Blueprint $t) => $t->dropConstrainedForeignId('computed_by'));
        }
    }
};
