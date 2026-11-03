<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('room_types', function (Blueprint $t) {
            $t->id();
            $t->foreignId('group_id')->constrained();
            $t->foreignId('hotel_id')->constrained();
            $t->string('code', 12);
            $t->string('name');
            $t->text('description')->nullable();
            $t->unsignedTinyInteger('max_adults')->default(2);
            $t->unsignedTinyInteger('max_children')->default(0);
            $t->string('bed_type')->nullable();
            $t->unsignedSmallInteger('size_sqm')->nullable();
            $t->jsonb('amenities')->default('[]');
            $t->unsignedBigInteger('base_rate')->default(0); // minor units, hotel currency
            $t->unsignedSmallInteger('sort')->default(0);
            $t->boolean('active')->default(true);
            $t->timestamps();
            $t->unique(['hotel_id', 'code']);
        });

        Schema::create('rooms', function (Blueprint $t) {
            $t->id();
            $t->foreignId('group_id')->constrained();
            $t->foreignId('hotel_id')->constrained();
            $t->foreignId('room_type_id')->constrained();
            $t->string('number', 10);
            $t->string('floor', 10)->nullable();
            $t->string('wing')->nullable();
            // vacant_clean | vacant_dirty | occupied_clean | occupied_dirty | inspected | out_of_order | out_of_service
            $t->string('hk_status')->default('vacant_clean');
            $t->boolean('occupied')->default(false);
            $t->boolean('active')->default(true);
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->unique(['hotel_id', 'number']);
            $t->index(['hotel_id', 'hk_status']);
        });

        Schema::create('outlets', function (Blueprint $t) {
            $t->id();
            $t->foreignId('group_id')->constrained();
            $t->foreignId('hotel_id')->constrained();
            $t->string('code', 12);
            $t->string('name');
            $t->string('kind')->default('restaurant'); // restaurant, bar, room_service, spa, laundry, minibar, shop, events
            $t->string('revenue_account')->nullable();
            $t->boolean('active')->default(true);
            $t->timestamps();
            $t->unique(['hotel_id', 'code']);
        });

        Schema::create('assets', function (Blueprint $t) {
            $t->id();
            $t->foreignId('group_id')->constrained();
            $t->foreignId('hotel_id')->constrained();
            $t->foreignId('room_id')->nullable()->constrained();
            $t->string('tag')->nullable();
            $t->string('name');
            $t->string('category')->nullable();
            $t->string('location')->nullable();
            $t->date('purchased_on')->nullable();
            $t->unsignedBigInteger('cost')->default(0);
            $t->string('status')->default('in_service');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assets');
        Schema::dropIfExists('outlets');
        Schema::dropIfExists('rooms');
        Schema::dropIfExists('room_types');
    }
};
