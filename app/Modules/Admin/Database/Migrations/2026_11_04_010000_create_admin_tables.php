<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->text('mfa_recovery_codes')->nullable();
        });

        // Currency catalogue. XAF (FCFA) is the functional currency; amounts in the books stay integer XAF.
        Schema::create('currencies', function (Blueprint $t) {
            $t->string('code', 3)->primary();
            $t->string('name');
            $t->string('symbol', 8)->nullable();
            $t->unsignedTinyInteger('decimals')->default(2);
            $t->boolean('is_functional')->default(false);
            $t->boolean('active')->default(true);
            $t->timestamps();
        });

        DB::table('currencies')->insert([
            ['code' => 'XAF', 'name' => 'Franc CFA (BEAC)', 'symbol' => 'FCFA', 'decimals' => 0, 'is_functional' => true, 'active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'EUR', 'name' => 'Euro', 'symbol' => '€', 'decimals' => 2, 'is_functional' => false, 'active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'USD', 'name' => 'US Dollar', 'symbol' => '$', 'decimals' => 2, 'is_functional' => false, 'active' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);
        // EUR/XAF is fixed by the CFA peg.
        DB::table('exchange_rates')->insertOrIgnore(['from' => 'EUR', 'to' => 'XAF', 'date' => '2000-01-01', 'rate' => 655.957]);
    }

    public function down(): void
    {
        DB::table('exchange_rates')->where(['from' => 'EUR', 'to' => 'XAF', 'date' => '2000-01-01'])->delete();
        Schema::dropIfExists('currencies');
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('mfa_recovery_codes'));
    }
};
