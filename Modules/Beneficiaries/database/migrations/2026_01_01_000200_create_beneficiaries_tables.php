<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per beneficiary (front BeneficiaryProfile + BeneficiaryRecord). A portal login lives in users (beneficiary_id).
        Schema::create('beneficiaries', function (Blueprint $table) {
            $table->string('id', 32)->primary(); // BEN-0001
            $table->string('first_name')->default('');
            $table->string('father_name')->default('');
            $table->string('grand_name')->default('');
            $table->string('family_name')->default('');
            $table->string('national_id', 32)->unique();
            $table->string('id_type')->nullable();
            $table->string('birth_date', 10)->default('');
            $table->string('gender')->default('');
            $table->string('nationality')->default('');
            $table->string('mobile')->default('');
            $table->string('email')->default('');
            $table->string('city')->default('');
            $table->string('district')->default('');
            $table->string('address')->default('');
            $table->string('status', 16)->default('active'); // active | inactive | suspended
            $table->boolean('has_portal_account')->default(false);
            $table->timestamps();
        });

        Schema::create('beneficiary_notes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('beneficiary_id', 32)->index();
            $table->dateTime('at');
            $table->string('author');
            $table->string('author_id', 64)->nullable();
            $table->string('role')->default('');
            $table->text('text');
            $table->string('visibility', 16)->default('internal'); // internal | beneficiary
        });

        Schema::create('beneficiary_activity', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('beneficiary_id', 32)->index();
            $table->dateTime('at');
            $table->string('actor');
            $table->string('role')->default('');
            $table->text('text');
            $table->string('case_id', 32)->nullable();
            $table->string('kind', 16)->default('account'); // account | request | note
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('beneficiary_activity');
        Schema::dropIfExists('beneficiary_notes');
        Schema::dropIfExists('beneficiaries');
    }
};
