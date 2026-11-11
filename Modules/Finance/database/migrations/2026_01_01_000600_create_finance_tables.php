<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** All amounts are integer halalas (SAR × 100). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallets', function (Blueprint $table) {
            $table->string('id', 32)->primary(); // WAL-001
            $table->string('name_ar');
            $table->string('name_en')->nullable();
            $table->text('description')->nullable();
            $table->string('category')->default('عام');
            $table->json('eligible_service_ids');
            $table->string('status', 16)->default('ACTIVE');
            $table->json('admin_ids');
            $table->timestamps();
        });

        Schema::create('wallet_ledger', function (Blueprint $table) {
            $table->string('id', 32)->primary(); // LED-00001
            $table->string('wallet_id', 32)->index();
            $table->string('case_id', 32)->nullable()->index();
            $table->string('reservation_id', 32)->nullable();
            $table->bigInteger('amount_halalas');
            $table->string('type', 32); // OPENING | FUNDING_ADDED | ADJUSTMENT | RESERVATION | RESERVATION_RELEASE | RESERVATION_CANCELLED | COMMITMENT_CONVERSION | COMMITMENT_REVERSAL | DEDUCTION
            $table->text('note')->nullable();
            $table->string('created_by');
            $table->dateTime('created_at', 3);
        });

        Schema::create('funding_reservations', function (Blueprint $table) {
            $table->string('id', 32)->primary(); // RSV-xxxx
            $table->string('case_id', 32)->index();
            $table->string('wallet_id', 32)->index();
            $table->string('allocation_id', 32);
            $table->string('approval_id', 48)->index();
            $table->bigInteger('amount_halalas');
            $table->string('status', 16)->index(); // ACTIVE | RELEASED | CONVERTED | CANCELLED | REVERSED
            $table->dateTime('created_at', 3);
            $table->string('created_by');
            $table->dateTime('closed_at')->nullable();
            $table->text('closed_reason')->nullable();
        });

        Schema::create('financial_approvals', function (Blueprint $table) {
            $table->string('id', 48)->primary(); // FA-REQ-000124-xxxx
            $table->string('case_id', 32)->index();
            $table->string('selected_quotation_id', 32)->nullable();
            $table->string('provider_id', 32)->nullable();
            $table->string('provider_name')->nullable();
            $table->bigInteger('total_cost_halalas')->default(0);
            $table->text('costing_basis')->nullable();
            $table->json('allocations');
            $table->unsignedInteger('version')->default(1);
            $table->unsignedInteger('config_version');
            $table->string('order', 32);
            $table->string('stage', 32)->index();
            $table->string('accountant_id', 64)->nullable();
            $table->json('split_approved')->nullable();
            $table->boolean('reserved')->default(false);
            $table->json('executive')->nullable();
            $table->json('history');
            $table->timestamps();
        });

        // Versioned approval-order / letter configuration: one row per version, current = max(version).
        Schema::create('finance_configs', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('version')->unique();
            $table->string('order', 32);
            $table->string('signatory_name')->default('');
            $table->string('signatory_title')->default('');
            $table->unsignedInteger('letter_validity_days')->nullable();
            $table->string('updated_by')->default('النظام');
            $table->timestamps();
        });

        Schema::create('finance_events', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->dateTime('at', 3)->index();
            $table->string('actor');
            $table->string('actor_id', 64)->nullable();
            $table->string('case_id', 32)->index();
            $table->string('action');
            $table->bigInteger('amount_halalas')->nullable();
            $table->string('wallet_id', 32)->nullable();
            $table->text('reason')->nullable();
            $table->string('from_stage', 32)->nullable(); // stage code
            $table->string('to_stage', 32)->nullable();   // stage code
        });
    }

    public function down(): void
    {
        foreach (['finance_events', 'finance_configs', 'financial_approvals', 'funding_reservations', 'wallet_ledger', 'wallets'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
