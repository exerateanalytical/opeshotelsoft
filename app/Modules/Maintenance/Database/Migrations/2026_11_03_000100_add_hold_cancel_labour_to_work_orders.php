<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_orders', function (Blueprint $t) {
            $t->unsignedBigInteger('labour_cost')->default(0);
            $t->string('held_from', 20)->nullable();
            $t->string('hold_reason')->nullable();
            $t->string('cancel_reason')->nullable();
            $t->timestamp('cancelled_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('work_orders', function (Blueprint $t) {
            $t->dropColumn(['labour_cost', 'held_from', 'hold_reason', 'cancel_reason', 'cancelled_at']);
        });
    }
};
