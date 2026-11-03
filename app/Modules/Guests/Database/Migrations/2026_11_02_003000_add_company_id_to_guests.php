<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('guests', function (Blueprint $t) {
            $t->foreignId('company_id')->nullable()->after('profession')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('guests', function (Blueprint $t) {
            $t->dropConstrainedForeignId('company_id');
        });
    }
};
