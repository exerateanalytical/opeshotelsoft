<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channel_connections', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique(); // used in inbound webhook URLs
            $t->foreignId('group_id')->constrained();
            $t->foreignId('hotel_id')->constrained();
            $t->string('provider'); // booking_com, expedia, tripadvisor, channex, myallocator, cloudbeds, siteminder
            $t->string('name');
            $t->string('property_code')->nullable(); // hotel id at the channel
            $t->text('credentials')->nullable();     // encrypted JSON
            $t->string('environment')->default('sandbox'); // sandbox, production
            $t->string('status')->default('draft');   // draft, testing, active, suspended
            $t->boolean('push_rates')->default(true);
            $t->boolean('push_availability')->default(true);
            $t->boolean('receive_reservations')->default(true);
            $t->unsignedSmallInteger('sync_days')->default(365);
            $t->jsonb('settings')->default('{}');
            $t->timestamp('last_push_at')->nullable();
            $t->timestamp('last_pull_at')->nullable();
            $t->timestamps();
            $t->unique(['hotel_id', 'provider']);
        });

        Schema::create('channel_room_mappings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('channel_connection_id')->constrained()->cascadeOnDelete();
            $t->foreignId('room_type_id')->constrained();
            $t->string('external_room_id');
            $t->unsignedSmallInteger('max_allotment')->nullable(); // cap rooms offered on this channel
            $t->unique(['channel_connection_id', 'external_room_id']);
        });

        Schema::create('channel_rate_mappings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('channel_connection_id')->constrained()->cascadeOnDelete();
            $t->foreignId('rate_plan_id')->constrained();
            $t->string('external_rate_id');
            $t->decimal('markup_percent', 6, 2)->default(0); // e.g. +15 to cover commission
            $t->unique(['channel_connection_id', 'external_rate_id']);
        });

        Schema::create('channel_sync_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('channel_connection_id')->constrained()->cascadeOnDelete();
            $t->string('direction'); // outbound, inbound
            $t->string('kind');      // ari, reservation, test
            $t->string('status');    // queued, sent, dry_run, failed, processed, rejected
            $t->unsignedSmallInteger('http_status')->nullable();
            $t->unsignedSmallInteger('attempts')->default(0);
            $t->longText('request')->nullable();
            $t->longText('response')->nullable();
            $t->text('error')->nullable();
            $t->timestamps();
            $t->index(['channel_connection_id', 'created_at']);
        });

        Schema::create('channel_reservations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('channel_connection_id')->constrained();
            $t->string('external_ref');
            $t->string('external_status');
            $t->string('version')->nullable(); // channel's modification stamp
            $t->foreignId('reservation_id')->nullable()->constrained();
            $t->jsonb('payload');
            $t->timestamps();
            $t->unique(['channel_connection_id', 'external_ref']);
        });
    }

    public function down(): void
    {
        foreach (['channel_reservations', 'channel_sync_logs', 'channel_rate_mappings', 'channel_room_mappings', 'channel_connections'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
