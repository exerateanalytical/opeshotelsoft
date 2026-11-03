<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $t) {
            $t->string('photo_path')->nullable()->after('email'); // public web path (/demo/avatar.png) or a path on the public disk
        });
    }

    public function down(): void
    {
        Schema::table('employees', fn (Blueprint $t) => $t->dropColumn('photo_path'));
    }
};
