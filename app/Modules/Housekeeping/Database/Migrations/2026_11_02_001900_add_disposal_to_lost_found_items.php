<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lost_found_items', function (Blueprint $t) {
            $t->string('disposal_reason')->nullable();
            $t->timestamp('disposed_at')->nullable();
            $t->foreignId('disposed_by')->nullable()->constrained('users');
        });
    }

    public function down(): void
    {
        Schema::table('lost_found_items', function (Blueprint $t) {
            $t->dropConstrainedForeignId('disposed_by');
            $t->dropColumn(['disposal_reason', 'disposed_at']);
        });
    }
};
