<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_contacts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('group_id')->constrained();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('guest_id')->nullable()->constrained(); // a contact who also stays with us
            $t->string('name');
            $t->string('role')->nullable(); // booker, accounts, travel manager...
            $t->string('email')->nullable();
            $t->string('phone')->nullable();
            $t->boolean('primary')->default(false);
            $t->timestamps();
            $t->index(['group_id', 'company_id']);
        });

        // Data-protection requests: anonymise (or delete) a guest's personal data. Financial rows are kept.
        Schema::create('guest_privacy_requests', function (Blueprint $t) {
            $t->id();
            $t->foreignId('group_id')->constrained();
            $t->foreignId('guest_id')->constrained();
            $t->string('kind'); // anonymise, delete
            $t->string('status')->default('pending'); // pending, approved (executed), rejected
            $t->text('reason');
            $t->foreignId('requested_by')->nullable()->constrained('users');
            $t->foreignId('decided_by')->nullable()->constrained('users');
            $t->timestamp('decided_at')->nullable();
            $t->text('decision_note')->nullable();
            $t->timestamps();
            $t->index(['group_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guest_privacy_requests');
        Schema::dropIfExists('company_contacts');
    }
};
