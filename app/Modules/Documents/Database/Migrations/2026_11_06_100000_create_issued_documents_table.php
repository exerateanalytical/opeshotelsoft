<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('issued_documents', function (Blueprint $t) {
            $t->id();
            $t->foreignId('group_id')->constrained()->cascadeOnDelete();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('type', 60);
            $t->string('number', 80);
            $t->string('source_type', 60)->nullable();
            $t->unsignedBigInteger('source_id')->nullable();
            $t->timestampTz('issued_at');
            $t->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $t->char('content_hash', 64);
            $t->bigInteger('amount')->nullable();
            $t->string('currency', 3)->nullable();
            $t->string('locale', 5)->default('fr');
            $t->string('status', 12)->default('valid'); // valid | voided | replaced
            $t->foreignId('replaced_by_id')->nullable()->constrained('issued_documents')->nullOnDelete();
            $t->string('status_reason', 255)->nullable();
            $t->timestampTz('status_at')->nullable();
            $t->string('code', 40)->unique();
            $t->unsignedInteger('copy_count')->default(0);
            $t->timestamps();
            $t->index(['hotel_id', 'type', 'source_type', 'source_id']);
        });

        Schema::create('issued_document_reprints', function (Blueprint $t) {
            $t->id();
            $t->foreignId('issued_document_id')->constrained('issued_documents')->cascadeOnDelete();
            $t->unsignedInteger('copy_number');
            $t->foreignId('printed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampTz('printed_at');
            $t->string('ip', 45)->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('issued_document_reprints');
        Schema::dropIfExists('issued_documents');
    }
};
