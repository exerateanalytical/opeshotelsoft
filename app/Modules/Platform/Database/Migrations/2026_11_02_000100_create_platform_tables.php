<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('groups', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('slug')->unique();
            $t->string('legal_name')->nullable();
            $t->string('country', 2)->default('CM');
            $t->string('default_currency', 3)->default('XAF');
            $t->string('default_locale', 5)->default('fr');
            $t->string('status')->default('active');
            $t->jsonb('settings')->default('{}');
            $t->timestamps();
        });

        Schema::create('hotels', function (Blueprint $t) {
            $t->id();
            $t->foreignId('group_id')->constrained();
            $t->string('code', 12);
            $t->string('name');
            $t->string('legal_name')->nullable();
            $t->string('tax_id')->nullable();
            $t->string('trade_register')->nullable();
            $t->string('country', 2)->default('CM');
            $t->string('city')->nullable();
            $t->string('address')->nullable();
            $t->string('phone')->nullable();
            $t->string('email')->nullable();
            $t->unsignedTinyInteger('stars')->nullable();
            $t->string('currency', 3)->default('XAF');
            $t->string('timezone')->default('Africa/Douala');
            $t->string('locale', 5)->default('fr');
            $t->time('check_in_time')->default('14:00');
            $t->time('check_out_time')->default('12:00');
            $t->date('business_date')->nullable();
            $t->string('status')->default('active');
            $t->jsonb('settings')->default('{}');
            $t->timestamps();
            $t->unique(['group_id', 'code']);
        });

        Schema::table('users', function (Blueprint $t) {
            $t->foreignId('group_id')->nullable()->after('id')->constrained();
            $t->boolean('is_platform_admin')->default(false);
            $t->boolean('all_hotels')->default(false);
            $t->string('locale', 5)->default('fr');
            $t->string('phone')->nullable();
            $t->string('pos_pin')->nullable();
            $t->string('status')->default('active');
            $t->text('mfa_secret')->nullable();
            $t->timestamp('mfa_confirmed_at')->nullable();
        });

        Schema::create('hotel_user', function (Blueprint $t) {
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->primary(['hotel_id', 'user_id']);
        });

        // Transactional outbox / event store
        Schema::create('domain_events', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->string('type')->index();
            $t->unsignedSmallInteger('version')->default(1);
            $t->unsignedBigInteger('group_id')->nullable()->index();
            $t->unsignedBigInteger('hotel_id')->nullable()->index();
            $t->string('aggregate_type')->nullable();
            $t->string('aggregate_id')->nullable();
            $t->unsignedBigInteger('actor_id')->nullable();
            $t->string('correlation_id')->nullable();
            $t->jsonb('payload');
            $t->timestamp('occurred_at');
            $t->timestamp('dispatched_at')->nullable()->index();
            $t->index(['aggregate_type', 'aggregate_id']);
        });

        Schema::create('event_consumptions', function (Blueprint $t) {
            $t->unsignedBigInteger('event_id');
            $t->string('consumer');
            $t->timestamp('consumed_at');
            $t->primary(['event_id', 'consumer']);
        });

        Schema::create('idempotency_keys', function (Blueprint $t) {
            $t->id();
            $t->string('key');
            $t->string('scope');
            $t->string('request_hash', 64);
            $t->unsignedSmallInteger('status_code');
            $t->longText('response_body')->nullable();
            $t->timestamp('created_at');
            $t->unique(['key', 'scope']);
        });

        Schema::create('number_sequences', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('hotel_id');
            $t->string('name');
            $t->unsignedSmallInteger('year');
            $t->unsignedBigInteger('last')->default(0);
            $t->unique(['hotel_id', 'name', 'year']);
        });

        Schema::create('webhook_subscriptions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('group_id')->constrained();
            $t->unsignedBigInteger('hotel_id')->nullable();
            $t->string('url');
            $t->jsonb('event_types');
            $t->string('secret');
            $t->boolean('active')->default(true);
            $t->timestamps();
        });

        Schema::create('webhook_deliveries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('webhook_subscription_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('event_id');
            $t->string('status')->default('pending');
            $t->unsignedSmallInteger('attempts')->default(0);
            $t->unsignedSmallInteger('response_code')->nullable();
            $t->text('last_error')->nullable();
            $t->timestamp('next_attempt_at')->nullable();
            $t->timestamps();
            $t->unique(['webhook_subscription_id', 'event_id']);
        });

        Schema::create('api_usage', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('group_id')->nullable();
            $t->string('client_id')->nullable();
            $t->date('day');
            $t->unsignedBigInteger('requests')->default(0);
            $t->unique(['group_id', 'client_id', 'day']);
        });
    }

    public function down(): void
    {
        foreach (['api_usage', 'webhook_deliveries', 'webhook_subscriptions', 'number_sequences', 'idempotency_keys', 'event_consumptions', 'domain_events', 'hotel_user'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('users', function (Blueprint $t) {
            $t->dropConstrainedForeignId('group_id');
            $t->dropColumn(['is_platform_admin', 'all_hotels', 'locale', 'phone', 'pos_pin', 'status', 'mfa_secret', 'mfa_confirmed_at']);
        });
        Schema::dropIfExists('hotels');
        Schema::dropIfExists('groups');
    }
};
