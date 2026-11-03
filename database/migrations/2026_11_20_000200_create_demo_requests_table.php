<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Platform-level leads from the public landing page; not tied to any hotel or group.
        Schema::create('demo_requests', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('hotel')->nullable();
            $t->string('city')->nullable();
            $t->unsignedSmallInteger('rooms')->nullable();
            $t->string('phone', 40)->nullable();
            $t->string('email')->nullable();
            $t->text('message')->nullable();
            $t->string('locale', 5)->default('fr');
            $t->string('status')->default('new'); // new, contacted, demo_done, won, lost
            $t->string('ip', 45)->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demo_requests');
    }
};
