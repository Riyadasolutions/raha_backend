<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_authorizations', function (Blueprint $table) {
            $table->string('id', 32)->primary(); // AUL-xxxx
            $table->string('authorization_number', 32)->unique(); // AUTH-2026-00001
            $table->string('case_id', 32)->index();
            $table->string('provider_id', 32)->index();
            $table->string('provider_name');
            $table->string('quotation_id', 32)->nullable();
            $table->string('financial_approval_id', 48);
            $table->string('service_id', 32)->nullable();
            $table->string('service_name');
            $table->string('beneficiary_name')->nullable();
            $table->text('description');
            $table->decimal('quantity', 12, 2);
            $table->string('unit');
            $table->bigInteger('amount_halalas');
            $table->text('scope');
            $table->string('expected_start', 32);
            $table->string('valid_until', 32)->nullable();
            $table->string('approval_ref');
            $table->string('signatory_name')->default('');
            $table->string('signatory_title')->default('');
            $table->string('status', 16)->index(); // ISSUED | CANCELLED | REPLACED
            $table->unsignedInteger('version')->default(1);
            $table->string('replaces_id', 32)->nullable();
            $table->string('replaced_by_id', 32)->nullable();
            $table->dateTime('issued_at')->nullable();
            $table->string('issued_by')->nullable();
            $table->string('closed_by')->nullable();
            $table->text('closed_reason')->nullable();
            $table->json('document')->nullable(); // issuance-time snapshot
            $table->timestamps();
        });

        Schema::create('service_assignments', function (Blueprint $table) {
            $table->string('id', 32)->primary(); // ASG-00001
            $table->string('case_id', 32)->index();
            $table->string('provider_id', 32)->index();
            $table->string('quotation_id', 32)->nullable();
            $table->string('authorization_id', 32)->index();
            $table->string('service_id', 32)->nullable();
            $table->text('scope');
            $table->decimal('quantity', 12, 2);
            $table->bigInteger('amount_halalas');
            $table->dateTime('assigned_at');
            $table->string('expected_start', 32);
            $table->string('status', 16); // ASSIGNED | ACKNOWLEDGED
            $table->dateTime('acknowledged_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_assignments');
        Schema::dropIfExists('service_authorizations');
    }
};
