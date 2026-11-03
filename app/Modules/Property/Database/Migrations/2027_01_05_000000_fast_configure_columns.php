<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Fast Configure: richer room type attributes (bed configuration, pricing uplifts). size_sqm and rooms.view already exist. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('room_types', function (Blueprint $t) {
            $t->jsonb('bed_config')->nullable();
            $t->unsignedSmallInteger('beds_total')->default(0);
            $t->string('view', 30)->nullable();
            $t->boolean('smoking')->default(false);
            $t->unsignedBigInteger('extra_adult_price')->default(0);
            $t->unsignedBigInteger('extra_child_price')->default(0);
            $t->decimal('weekend_uplift_percent', 6, 2)->default(0);
            $t->decimal('high_season_uplift_percent', 6, 2)->default(0);
        });
        Schema::table('rooms', function (Blueprint $t) {
            $t->boolean('smoking')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('rooms', fn (Blueprint $t) => $t->dropColumn('smoking'));
        Schema::table('room_types', fn (Blueprint $t) => $t->dropColumn(['bed_config', 'beds_total', 'view', 'smoking', 'extra_adult_price', 'extra_child_price', 'weekend_uplift_percent', 'high_season_uplift_percent']));
    }
};
