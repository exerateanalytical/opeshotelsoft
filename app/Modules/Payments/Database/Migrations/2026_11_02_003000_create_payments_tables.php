<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Per-hotel gateway settings. Credentials are an encrypted JSON blob.
        Schema::create('payment_gateways', function (Blueprint $t) {
            $t->id();
            $t->foreignId('group_id')->constrained();
            $t->foreignId('hotel_id')->constrained();
            $t->string('provider', 32); // mtn_momo | orange_money | card
            $t->boolean('enabled')->default(true);
            $t->string('mode', 16)->default('sandbox'); // sandbox | live
            $t->text('credentials')->nullable();
            $t->timestamps();
            $t->unique(['hotel_id', 'provider']);
        });

        Schema::create('payment_intents', function (Blueprint $t) {
            $t->id();
            $t->foreignId('group_id')->constrained();
            $t->foreignId('hotel_id')->constrained();
            $t->uuid('reference')->unique();
            $t->string('provider', 32);
            $t->string('driver', 32); // actual driver used (dry_run when no credentials)
            $t->unsignedBigInteger('amount');
            $t->string('currency', 3)->default('XAF');
            $t->foreignId('folio_id')->nullable()->constrained();
            $t->foreignId('reservation_id')->nullable()->constrained();
            $t->string('payer_phone', 30)->nullable();
            $t->string('payer_email')->nullable();
            $t->string('status', 16)->default('pending'); // pending | succeeded | failed | expired
            $t->string('provider_reference')->nullable();
            $t->string('redirect_url', 1000)->nullable();
            $t->string('failure_reason')->nullable();
            $t->unsignedBigInteger('refunded_amount')->default(0);
            $t->unsignedBigInteger('folio_line_id')->nullable();
            $t->string('channel', 16)->default('staff'); // staff | public
            $t->foreignId('created_by')->nullable()->constrained('users');
            $t->jsonb('meta')->default('{}');
            $t->timestamp('expires_at')->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->timestamps();
            $t->index(['hotel_id', 'status']);
            $t->index(['provider', 'provider_reference']);
        });

        // Every callback received, for audit and idempotency (unique event key per provider).
        Schema::create('payment_callbacks', function (Blueprint $t) {
            $t->id();
            $t->string('provider', 32);
            $t->string('event_key', 128);
            $t->foreignId('payment_intent_id')->nullable()->constrained();
            $t->boolean('signature_valid')->default(false);
            $t->jsonb('payload')->default('{}');
            $t->string('outcome', 32)->nullable();
            $t->timestamps();
            $t->unique(['provider', 'event_key']);
        });

        Schema::create('payment_refunds', function (Blueprint $t) {
            $t->id();
            $t->foreignId('group_id')->constrained();
            $t->foreignId('hotel_id')->constrained();
            $t->foreignId('payment_intent_id')->constrained();
            $t->unsignedBigInteger('amount');
            $t->string('reason');
            $t->string('status', 16)->default('succeeded');
            $t->string('provider_reference')->nullable();
            $t->unsignedBigInteger('folio_line_id')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_refunds');
        Schema::dropIfExists('payment_callbacks');
        Schema::dropIfExists('payment_intents');
        Schema::dropIfExists('payment_gateways');
    }
};
