<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_requests', function (Blueprint $t) {
            $t->id();
            $t->foreignId('group_id')->constrained();
            $t->foreignId('hotel_id')->constrained();
            $t->unsignedBigInteger('reservation_id')->nullable();
            $t->unsignedBigInteger('room_id')->nullable();
            $t->string('room_number', 20);
            $t->unsignedBigInteger('guest_id')->nullable();
            $t->string('category', 24);
            $t->string('department', 24);
            $t->jsonb('items')->default('[]');
            $t->text('note')->nullable();
            $t->string('priority', 10)->default('normal'); // low, normal, high, urgent
            $t->string('source', 20); // member_portal, whatsapp, reception, room_phone, staff
            $t->string('status', 16)->default('new'); // new, acknowledged, in_progress, done, cancelled
            $t->foreignId('created_by')->nullable()->constrained('users');
            $t->foreignId('assigned_to')->nullable()->constrained('users');
            $t->foreignId('acknowledged_by')->nullable()->constrained('users');
            $t->timestamp('acknowledged_at')->nullable();
            $t->timestamp('started_at')->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->text('completion_note')->nullable();
            $t->timestamp('cancelled_at')->nullable();
            $t->timestamp('due_at');
            $t->timestamp('escalated_at')->nullable(); // spoken "late" at half the SLA
            $t->timestamp('overdue_at')->nullable();   // manager alert sent past the SLA
            $t->timestamps();
            $t->index(['hotel_id', 'status', 'created_at']);
            $t->index(['hotel_id', 'room_number', 'category']);
        });

        // Hotel-tunable SLA minutes per category and the message hook switch.
        Schema::create('service_request_settings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('group_id')->constrained();
            $t->foreignId('hotel_id')->constrained();
            $t->jsonb('sla')->default('{}'); // category => minutes
            $t->boolean('voice_messages')->default(true);
            $t->timestamps();
            $t->unique('hotel_id');
        });

        // The live feed the voice engine polls: one row per thing worth announcing. Cursor = id.
        Schema::create('service_voice_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('group_id')->constrained();
            $t->foreignId('hotel_id')->constrained();
            $t->string('kind', 12); // request, late, overdue, message
            $t->unsignedBigInteger('service_request_id')->nullable();
            $t->string('room_number', 20);
            $t->string('department', 24);
            $t->jsonb('payload')->default('{}');
            $t->timestamp('created_at')->useCurrent();
            $t->index(['hotel_id', 'id']);
        });

        // Per-user voice preferences (follow the user to any device).
        Schema::create('service_voice_prefs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->jsonb('settings')->default('{}');
            $t->timestamps();
            $t->unique('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_voice_prefs');
        Schema::dropIfExists('service_voice_events');
        Schema::dropIfExists('service_request_settings');
        Schema::dropIfExists('service_requests');
    }
};
