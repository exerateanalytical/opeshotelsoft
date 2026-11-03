<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Which WhatsApp Business phone number belongs to which hotel (inbound routing).
        Schema::create('whatsapp_accounts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('group_id')->constrained();
            $t->foreignId('hotel_id')->constrained();
            $t->string('phone_number_id', 64)->unique();
            $t->string('display_phone', 32)->nullable();
            $t->boolean('active')->default(true);
            $t->timestamps();
        });

        // Template registry: Meta-approved templates per hotel, journey step and language.
        Schema::create('whatsapp_templates', function (Blueprint $t) {
            $t->id();
            $t->foreignId('group_id')->constrained();
            $t->foreignId('hotel_id')->constrained();
            $t->string('journey', 32);           // booking_confirmation, pre_arrival, welcome, bill, review, ...
            $t->string('name', 64);              // Meta template name
            $t->string('locale', 5);             // fr, en
            $t->string('category', 16)->default('utility'); // utility, marketing
            $t->text('body');                    // {{1}} style positional placeholders mapped by params
            $t->json('params')->nullable();      // ordered variable names for {{1}}..{{n}}
            $t->string('meta_status', 16)->default('draft'); // draft, submitted, approved, rejected, paused
            $t->string('meta_template_id', 64)->nullable();
            $t->string('rejected_reason')->nullable();
            $t->timestamps();
            $t->unique(['hotel_id', 'name', 'locale']);
        });

        // Opt-out list per phone (STOP), group-wide.
        Schema::create('whatsapp_opt_outs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('group_id')->constrained();
            $t->string('wa_id', 32);
            $t->string('keyword', 16)->nullable();
            $t->timestamp('opted_out_at');
            $t->timestamps();
            $t->unique(['group_id', 'wa_id']);
        });

        // One conversation per hotel, phone and stay.
        Schema::create('whatsapp_conversations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('group_id')->constrained();
            $t->foreignId('hotel_id')->constrained();
            $t->string('wa_id', 32);
            $t->string('name')->nullable();
            $t->unsignedBigInteger('guest_id')->nullable()->index();
            $t->unsignedBigInteger('reservation_id')->nullable()->index();
            $t->string('locale', 5)->default('fr');
            $t->string('status', 16)->default('open'); // open, closed
            $t->unsignedBigInteger('assigned_to')->nullable();
            $t->unsignedInteger('unread')->default(0);
            $t->timestamp('last_inbound_at')->nullable();
            $t->timestamp('last_message_at')->nullable();
            $t->timestamps();
            $t->index(['hotel_id', 'wa_id']);
        });

        Schema::create('whatsapp_messages', function (Blueprint $t) {
            $t->id();
            $t->foreignId('group_id')->constrained();
            $t->foreignId('hotel_id')->constrained();
            $t->foreignId('conversation_id')->constrained('whatsapp_conversations');
            $t->string('direction', 3); // in, out
            $t->string('type', 16);     // text, template, image, document, audio, video, location, interactive
            $t->text('body')->nullable();
            $t->string('template_name', 64)->nullable();
            $t->json('media')->nullable();
            $t->string('journey', 32)->nullable();
            $t->unsignedBigInteger('reservation_id')->nullable();
            $t->string('wamid')->nullable()->unique();
            $t->string('status', 16); // received, queued, sent, delivered, read, failed, blocked
            $t->string('error')->nullable();
            $t->unsignedBigInteger('sent_by')->nullable();
            $t->boolean('dry_run')->default(false);
            $t->timestamp('delivered_at')->nullable();
            $t->timestamp('read_at')->nullable();
            $t->timestamps();
            $t->index(['journey', 'reservation_id']);
        });

        Schema::create('whatsapp_quick_replies', function (Blueprint $t) {
            $t->id();
            $t->foreignId('group_id')->constrained();
            $t->foreignId('hotel_id')->constrained();
            $t->string('title', 80);
            $t->text('body');
            $t->string('locale', 5)->default('fr');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['whatsapp_quick_replies', 'whatsapp_messages', 'whatsapp_conversations', 'whatsapp_opt_outs', 'whatsapp_templates', 'whatsapp_accounts'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
