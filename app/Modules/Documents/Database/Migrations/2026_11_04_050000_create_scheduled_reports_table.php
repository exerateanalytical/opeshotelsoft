<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduled_reports', function (Blueprint $t) {
            $t->id();
            $t->foreignId('group_id')->constrained();
            $t->foreignId('hotel_id')->constrained();
            $t->string('name');
            $t->string('report', 40);
            $t->jsonb('params')->default('{}');
            $t->string('format', 8)->default('pdf');
            $t->jsonb('recipients');
            $t->string('frequency', 64); // cron expression, e.g. "0 7 * * *"
            $t->boolean('active')->default(true);
            $t->foreignId('created_by')->constrained('users');
            $t->timestamp('next_run_at')->nullable();
            $t->timestamp('last_run_at')->nullable();
            $t->string('last_status')->nullable();
            $t->text('last_error')->nullable();
            $t->timestamps();
            $t->index(['active', 'next_run_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_reports');
    }
};
