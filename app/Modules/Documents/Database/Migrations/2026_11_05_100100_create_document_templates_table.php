<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Q1: per-hotel editable document texts (safe placeholders only, never Blade). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_templates', function (Blueprint $t) {
            $t->id();
            $t->foreignId('group_id')->constrained();
            $t->foreignId('hotel_id')->constrained();
            $t->string('kind', 30);   // invoice, quote, registration_card, confirmation, letter
            $t->string('locale', 2);  // fr, en
            $t->string('title')->nullable();
            $t->text('body');
            $t->foreignId('updated_by')->nullable()->constrained('users');
            $t->timestamps();
            $t->unique(['hotel_id', 'kind', 'locale']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_templates');
    }
};
