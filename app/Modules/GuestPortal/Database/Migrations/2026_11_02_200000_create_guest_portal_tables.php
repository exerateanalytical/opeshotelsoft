<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guest_portal_checkins', function (Blueprint $t) {
            $t->id();
            $t->foreignId('group_id')->constrained();
            $t->foreignId('hotel_id')->constrained();
            $t->foreignId('reservation_id')->unique()->constrained();
            $t->string('id_type', 20);
            $t->string('id_number', 60);
            $t->string('nationality', 2)->nullable();
            $t->time('eta')->nullable();
            $t->string('notes', 500)->nullable();
            $t->timestamp('submitted_at');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guest_portal_checkins');
    }
};
