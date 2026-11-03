<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // In-stay orders placed from the signed mobile ordering page; each becomes a POS room-charge check.
        Schema::create('guest_portal_orders', function (Blueprint $t) {
            $t->id();
            $t->foreignId('group_id')->constrained();
            $t->foreignId('hotel_id')->constrained();
            $t->unsignedBigInteger('reservation_id')->index();
            $t->unsignedBigInteger('pos_check_id')->nullable();
            $t->string('client_uuid', 64)->nullable()->unique();
            $t->bigInteger('total')->default(0);
            $t->string('status', 16)->default('placed');
            $t->text('notes')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guest_portal_orders');
    }
};
