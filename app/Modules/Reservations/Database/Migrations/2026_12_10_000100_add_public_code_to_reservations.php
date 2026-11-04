<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Group-unique public booking code. Reservation numbers are sequenced per hotel, and booking_ref is shared by the rooms of
 * one booking, so neither identifies a single booking across a hotel group. public_code does (unique per group).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $t) {
            $t->string('public_code', 12)->nullable();
        });

        $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
        $used = [];
        DB::table('reservations')->orderBy('id')->select('id', 'group_id')->chunkById(500, function ($rows) use (&$used, $alphabet) {
            foreach ($rows as $row) {
                do {
                    $code = '';
                    for ($i = 0; $i < 8; $i++) {
                        $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
                    }
                } while (isset($used[$row->group_id.'|'.$code]));
                $used[$row->group_id.'|'.$code] = true;
                DB::table('reservations')->where('id', $row->id)->update(['public_code' => $code]);
            }
        });

        Schema::table('reservations', function (Blueprint $t) {
            $t->unique(['group_id', 'public_code'], 'reservations_group_public_code_unique');
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $t) {
            $t->dropUnique('reservations_group_public_code_unique');
            $t->dropColumn('public_code');
        });
    }
};
