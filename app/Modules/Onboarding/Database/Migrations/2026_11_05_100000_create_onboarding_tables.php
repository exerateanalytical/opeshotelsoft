<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Q1: hotel branding (letterhead) columns, setup-wizard progress and import batches. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hotels', function (Blueprint $t) {
            $t->string('logo_path')->nullable();
            $t->string('brand_color', 7)->nullable();   // #RRGGBB
            $t->string('accent_color', 7)->nullable();
            $t->string('postal_box', 60)->nullable();
            $t->text('bank_details')->nullable();
            $t->text('document_footer')->nullable();
        });

        Schema::create('onboarding_progress', function (Blueprint $t) {
            $t->id();
            $t->foreignId('group_id')->constrained();
            $t->foreignId('hotel_id')->constrained();
            $t->string('current_step', 30)->default('group');
            $t->jsonb('completed')->default('{}'); // step => ISO timestamp
            $t->jsonb('data')->default('{}');      // draft answers kept while the wizard is resumed
            $t->timestamp('went_live_at')->nullable();
            $t->foreignId('went_live_by')->nullable()->constrained('users');
            $t->timestamps();
            $t->unique('hotel_id');
        });

        Schema::create('import_batches', function (Blueprint $t) {
            $t->id();
            $t->foreignId('group_id')->constrained();
            $t->foreignId('hotel_id')->constrained();
            $t->string('kind', 30); // rooms, guests, staff, opening_balances, stock_items
            $t->string('filename')->nullable();
            $t->boolean('dry_run')->default(true);
            $t->string('status', 20); // validated, rejected, imported
            $t->unsignedInteger('rows_total')->default(0);
            $t->unsignedInteger('rows_ok')->default(0);
            $t->unsignedInteger('rows_failed')->default(0);
            $t->jsonb('report')->default('[]');
            $t->foreignId('created_by')->nullable()->constrained('users');
            $t->timestamps();
            $t->index(['hotel_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_batches');
        Schema::dropIfExists('onboarding_progress');
        Schema::table('hotels', fn (Blueprint $t) => $t->dropColumn(['logo_path', 'brand_color', 'accent_color', 'postal_box', 'bank_details', 'document_footer']));
    }
};
