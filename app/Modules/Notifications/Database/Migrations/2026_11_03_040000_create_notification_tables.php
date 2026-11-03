<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Per-hotel templates: one row per event, channel and language.
        Schema::create('notification_templates', function (Blueprint $t) {
            $t->id();
            $t->foreignId('group_id')->constrained();
            $t->foreignId('hotel_id')->constrained();
            $t->string('event', 64);
            $t->string('channel', 16); // email, sms, whatsapp
            $t->string('locale', 5);   // fr, en
            $t->string('category', 16)->default('transactional'); // transactional, marketing
            $t->string('subject')->nullable();
            $t->text('body');
            $t->boolean('active')->default(true);
            $t->timestamps();
            $t->unique(['hotel_id', 'event', 'channel', 'locale']);
        });

        // Outbox: every message we try to send, with delivery state.
        Schema::create('notification_outbox', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('group_id')->nullable()->index();
            $t->unsignedBigInteger('hotel_id')->nullable()->index();
            $t->unsignedBigInteger('guest_id')->nullable();
            $t->string('event', 64);
            $t->string('channel', 16);
            $t->string('locale', 5);
            $t->string('category', 16);
            $t->string('recipient');
            $t->string('subject')->nullable();
            $t->text('body');
            $t->string('status', 16)->default('queued'); // queued, sent, retrying, failed, skipped
            $t->unsignedSmallInteger('attempts')->default(0);
            $t->unsignedSmallInteger('max_attempts')->default(5);
            $t->timestamp('next_attempt_at')->nullable();
            $t->text('error')->nullable();
            $t->string('driver', 32)->nullable();
            $t->string('provider_id')->nullable();
            $t->string('ref_type', 32)->nullable();
            $t->unsignedBigInteger('ref_id')->nullable();
            $t->unsignedBigInteger('source_event_id')->nullable();
            $t->timestamp('sent_at')->nullable();
            $t->timestamps();
            $t->index(['status', 'next_attempt_at']);
            $t->index(['event', 'ref_type', 'ref_id']);
        });

        // Guest channel preferences (marketing consent itself lives on guests.marketing_consent).
        Schema::create('notification_preferences', function (Blueprint $t) {
            $t->id();
            $t->foreignId('group_id')->constrained();
            $t->foreignId('guest_id')->constrained()->cascadeOnDelete();
            $t->boolean('email')->default(true);
            $t->boolean('sms')->default(true);
            $t->boolean('whatsapp')->default(false);
            $t->boolean('marketing')->default(true); // per-guest marketing opt-out on top of consent
            $t->timestamps();
            $t->unique('guest_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_preferences');
        Schema::dropIfExists('notification_outbox');
        Schema::dropIfExists('notification_templates');
    }
};
