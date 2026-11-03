<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hk_tasks', function (Blueprint $t) {
            $t->id();
            $t->foreignId('group_id')->constrained();
            $t->foreignId('hotel_id')->constrained();
            $t->foreignId('room_id')->constrained();
            $t->date('date');
            $t->string('kind')->default('stayover'); // departure, stayover, arrival_prep, deep_clean, turndown, inspection
            $t->string('status')->default('pending'); // pending, in_progress, done, inspected, failed, skipped
            $t->unsignedTinyInteger('priority')->default(3);
            $t->foreignId('assigned_to')->nullable()->constrained('users');
            $t->timestamp('started_at')->nullable();
            $t->timestamp('finished_at')->nullable();
            $t->foreignId('inspected_by')->nullable()->constrained('users');
            $t->jsonb('checklist')->default('[]');
            $t->jsonb('consumables')->default('[]'); // [{stock_item_id, quantity}]
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->unique(['room_id', 'date', 'kind']);
            $t->index(['hotel_id', 'date', 'status']);
        });

        Schema::create('lost_found_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('group_id')->constrained();
            $t->foreignId('hotel_id')->constrained();
            $t->string('description');
            $t->string('location')->nullable();
            $t->foreignId('room_id')->nullable()->constrained();
            $t->foreignId('guest_id')->nullable()->constrained();
            $t->foreignId('found_by')->nullable()->constrained('users');
            $t->date('found_on');
            $t->string('status')->default('stored'); // stored, returned, disposed
            $t->string('returned_to')->nullable();
            $t->timestamp('returned_at')->nullable();
            $t->timestamps();
        });

        Schema::create('work_orders', function (Blueprint $t) {
            $t->id();
            $t->foreignId('group_id')->constrained();
            $t->foreignId('hotel_id')->constrained();
            $t->string('number', 20);
            $t->foreignId('room_id')->nullable()->constrained();
            $t->foreignId('asset_id')->nullable()->constrained();
            $t->string('category')->default('general'); // electrical, plumbing, hvac, furniture, it, general
            $t->string('title');
            $t->text('description')->nullable();
            $t->string('priority')->default('normal'); // low, normal, high, urgent
            $t->string('status')->default('open'); // open, assigned, in_progress, on_hold, done, cancelled
            $t->boolean('room_out_of_order')->default(false);
            $t->foreignId('reported_by')->nullable()->constrained('users');
            $t->foreignId('assigned_to')->nullable()->constrained('users');
            $t->foreignId('pm_schedule_id')->nullable();
            $t->unsignedInteger('minutes_spent')->default(0);
            $t->unsignedBigInteger('parts_cost')->default(0);
            $t->timestamp('due_at')->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->text('resolution')->nullable();
            $t->timestamps();
            $t->unique(['hotel_id', 'number']);
        });

        Schema::create('pm_schedules', function (Blueprint $t) {
            $t->id();
            $t->foreignId('group_id')->constrained();
            $t->foreignId('hotel_id')->constrained();
            $t->foreignId('asset_id')->nullable()->constrained();
            $t->string('title');
            $t->string('category')->default('general');
            $t->unsignedSmallInteger('every_days');
            $t->date('next_due');
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['pm_schedules', 'work_orders', 'lost_found_items', 'hk_tasks'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
