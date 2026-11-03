<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Per-hotel rule switches/thresholds and owner digest recipients.
        Schema::create('insight_settings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('group_id')->constrained();
            $t->foreignId('hotel_id')->constrained();
            $t->jsonb('rules')->default('{}'); // rule => {enabled, ...thresholds}
            $t->boolean('digest_enabled')->default(false);
            $t->jsonb('digest_emails')->default('[]');
            $t->jsonb('digest_whatsapp')->default('[]');
            $t->string('digest_locale', 5)->default('fr');
            $t->timestamps();
            $t->unique('hotel_id');
        });

        // Leakage alerts. Never deleted: acknowledged then resolved with a note.
        Schema::create('insight_alerts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('group_id')->constrained();
            $t->foreignId('hotel_id')->constrained();
            $t->string('rule', 40);
            $t->string('severity', 10)->default('medium'); // low, medium, high
            $t->string('status', 16)->default('open'); // open, acknowledged, resolved
            $t->string('title');
            $t->text('detail')->nullable();
            $t->bigInteger('amount')->default(0); // FCFA at stake
            $t->jsonb('evidence')->default('[]'); // [{label, type, id, url}]
            $t->string('dedupe_key', 120);
            $t->date('business_date')->nullable();
            $t->unsignedBigInteger('source_event_id')->nullable();
            $t->timestamp('occurred_at');
            $t->foreignId('acknowledged_by')->nullable()->constrained('users');
            $t->timestamp('acknowledged_at')->nullable();
            $t->string('acknowledge_note')->nullable();
            $t->foreignId('resolved_by')->nullable()->constrained('users');
            $t->timestamp('resolved_at')->nullable();
            $t->text('resolution_note')->nullable();
            $t->timestamps();
            $t->unique(['hotel_id', 'dedupe_key']);
            $t->index(['hotel_id', 'status', 'occurred_at']);
        });

        Schema::create('insight_digests', function (Blueprint $t) {
            $t->id();
            $t->foreignId('group_id')->constrained();
            $t->foreignId('hotel_id')->constrained();
            $t->date('date');
            $t->jsonb('payload');
            $t->unsignedSmallInteger('messages')->default(0);
            $t->timestamp('sent_at')->nullable();
            $t->timestamps();
            $t->unique(['hotel_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('insight_digests');
        Schema::dropIfExists('insight_alerts');
        Schema::dropIfExists('insight_settings');
    }
};
