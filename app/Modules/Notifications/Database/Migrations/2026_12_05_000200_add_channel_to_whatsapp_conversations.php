<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // `whatsapp` (default) or `web` (reception chat from the member portal). Web conversations use wa_id "web-<guest_id>".
        Schema::table('whatsapp_conversations', function (Blueprint $t) {
            $t->string('channel', 12)->default('whatsapp')->after('hotel_id');
            $t->index(['hotel_id', 'channel', 'guest_id']);
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_conversations', function (Blueprint $t) {
            $t->dropIndex(['hotel_id', 'channel', 'guest_id']);
            $t->dropColumn('channel');
        });
    }
};
