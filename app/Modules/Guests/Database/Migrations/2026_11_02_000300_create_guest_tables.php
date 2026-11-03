<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Guest profiles are group-wide: one profile across all hotels of a group.
        Schema::create('guests', function (Blueprint $t) {
            $t->id();
            $t->foreignId('group_id')->constrained();
            $t->string('title', 10)->nullable();
            $t->string('first_name');
            $t->string('last_name');
            $t->string('email')->nullable();
            $t->string('phone')->nullable();
            $t->string('gender', 1)->nullable();
            $t->date('date_of_birth')->nullable();
            $t->string('nationality', 2)->nullable();
            $t->string('country', 2)->nullable();
            $t->string('city')->nullable();
            $t->string('address')->nullable();
            $t->string('language', 5)->default('fr');
            $t->string('id_type')->nullable(); // passport, national_id, residence_permit, driving_licence
            $t->string('id_number')->nullable();
            $t->date('id_expiry')->nullable();
            $t->string('id_issued_in', 2)->nullable();
            $t->string('profession')->nullable();
            $t->boolean('vip')->default(false);
            $t->boolean('blacklisted')->default(false);
            $t->text('blacklist_reason')->nullable();
            $t->jsonb('preferences')->default('{}');
            $t->text('notes')->nullable();
            $t->boolean('marketing_consent')->default(false);
            $t->timestamp('consent_at')->nullable();
            $t->foreignId('merged_into_id')->nullable()->constrained('guests');
            $t->timestamps();
            $t->softDeletes();
            $t->index(['group_id', 'last_name', 'first_name']);
            $t->index(['group_id', 'email']);
            $t->index(['group_id', 'phone']);
        });

        Schema::create('companies', function (Blueprint $t) {
            $t->id();
            $t->foreignId('group_id')->constrained();
            $t->string('kind')->default('corporate'); // corporate, travel_agent, ota, government, ngo
            $t->string('name');
            $t->string('tax_id')->nullable();
            $t->string('email')->nullable();
            $t->string('phone')->nullable();
            $t->string('address')->nullable();
            $t->string('country', 2)->nullable();
            $t->string('contact_name')->nullable();
            $t->decimal('commission_percent', 5, 2)->default(0);
            $t->boolean('city_ledger')->default(false); // may be invoiced on credit
            $t->unsignedBigInteger('credit_limit')->default(0);
            $t->unsignedSmallInteger('payment_terms_days')->default(30);
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('companies');
        Schema::dropIfExists('guests');
    }
};
