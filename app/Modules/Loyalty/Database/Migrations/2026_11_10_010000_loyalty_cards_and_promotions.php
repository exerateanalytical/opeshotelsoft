<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loyalty_cards', function (Blueprint $t) {
            $t->date('expires_on')->nullable();
            $t->timestamp('blocked_at')->nullable();
            $t->string('block_reason')->nullable();
            $t->foreignId('replaced_by_card_id')->nullable()->constrained('loyalty_cards');
        });

        Schema::create('loyalty_promotions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('group_id')->constrained();
            $t->string('name');
            $t->string('kind', 16); // multiplier, birthday, enrolment
            $t->decimal('multiplier', 5, 2)->default(1);
            $t->unsignedInteger('bonus_points')->default(0);
            $t->date('starts_on')->nullable();
            $t->date('ends_on')->nullable();
            $t->jsonb('outlet_ids')->nullable(); // null = every outlet and stays
            $t->boolean('active')->default(true);
            $t->timestamps();
            $t->index(['group_id', 'kind', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_promotions');
        Schema::table('loyalty_cards', function (Blueprint $t) {
            $t->dropConstrainedForeignId('replaced_by_card_id');
            $t->dropColumn(['expires_on', 'blocked_at', 'block_reason']);
        });
    }
};
