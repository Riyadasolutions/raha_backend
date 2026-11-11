<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Front RahaCase. Nested value objects stay JSON; collections are child tables.
        Schema::create('cases', function (Blueprint $table) {
            $table->string('id', 32)->primary(); // REQ-000124
            $table->string('beneficiary_id', 32)->index();
            $table->string('workflow_id', 32)->default('phase1-medical');
            $table->string('beneficiary');
            $table->string('national_id', 32)->default('');
            $table->string('mobile', 32)->nullable();
            $table->string('city')->default('');
            $table->string('service');
            $table->string('service_id', 32)->nullable()->index();
            $table->string('subservice')->nullable();
            $table->text('description')->nullable();
            $table->string('previously_received')->nullable();
            $table->json('medical')->nullable();
            $table->json('applicant')->nullable();
            $table->json('social')->nullable();
            $table->json('recipient')->nullable();
            $table->string('account_owner_id', 32)->nullable();
            $table->string('account_owner_name')->nullable();
            $table->string('request_for', 16)->nullable();
            $table->json('requirements')->nullable();
            $table->string('date');
            $table->string('updated');
            $table->string('stage');
            $table->string('assignee')->default('');
            $table->string('status', 32)->index();
            $table->boolean('priority')->nullable();
            $table->json('return_info')->nullable();
            $table->json('return_history')->nullable();
            $table->text('reject_reason')->nullable();
            $table->string('provider')->nullable();
            $table->string('provider_id', 32)->nullable()->index();
            $table->string('assignment_type')->nullable();
            $table->boolean('provider_accepted')->nullable();
            $table->dateTime('assigned_at')->nullable();
            $table->json('appointment')->nullable();
            $table->json('invoice')->nullable();
            $table->string('verification_code', 16)->nullable();
            $table->boolean('service_confirmed')->nullable();
            $table->json('evaluation')->nullable();
            $table->boolean('survey_pending')->nullable();
            $table->json('request_survey')->nullable();
            $table->boolean('quotation_required')->nullable();
            $table->json('finance')->nullable();
            $table->json('preferred_quotation')->nullable();
            $table->json('fast_track')->nullable();
            $table->json('visit_plan')->nullable();
            $table->timestamps();
        });

        Schema::create('case_activities', function (Blueprint $table) {
            $table->string('id', 64)->primary();
            $table->string('case_id', 32)->index();
            $table->dateTime('at', 3);
            $table->text('text');
            $table->string('actor');
            $table->string('actor_id', 64)->nullable();
            $table->string('visibility', 16); // internal | beneficiary | both
            $table->string('action', 48)->nullable();
            $table->string('from_stage', 32)->nullable();
            $table->string('to_stage', 32)->nullable();
            $table->text('reason')->nullable();
        });

        Schema::create('case_comments', function (Blueprint $table) {
            $table->string('id', 64)->primary();
            $table->string('case_id', 32)->index();
            $table->dateTime('at', 3);
            $table->text('text');
            $table->string('author');
            $table->string('author_id', 64)->nullable();
            $table->string('visibility', 16);
        });

        Schema::create('case_documents', function (Blueprint $table) {
            $table->string('id', 64)->primary();
            $table->string('case_id', 32)->index();
            $table->string('name');
            $table->string('category');
            $table->string('type', 8); // PDF | JPG | PNG | IMG
            $table->unsignedBigInteger('size')->default(0);
            $table->string('uploaded_by');
            $table->string('uploaded_by_id', 64)->nullable();
            $table->string('visibility', 16)->default('both');
            $table->dateTime('at', 3);
            $table->string('doc_type', 64)->nullable();
            $table->string('report_date', 10)->nullable();
            $table->json('review')->nullable();
            $table->string('mime', 128)->nullable();
            $table->string('path')->nullable(); // private disk; null for legacy metadata-only docs
            $table->boolean('superseded')->default(false);
        });

        Schema::create('case_tasks', function (Blueprint $table) {
            $table->string('id', 96)->primary();
            $table->string('case_id', 32)->index();
            $table->string('stage', 32);
            $table->string('role', 32);
            $table->string('assigned_user_id', 64)->nullable()->index();
            $table->string('assigned_user_name')->nullable();
            $table->dateTime('created_at', 3);
            $table->dateTime('due_at')->nullable();
            $table->dateTime('claimed_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->string('completed_by')->nullable();
            $table->string('completed_by_id', 64)->nullable()->index();
            $table->string('status', 16)->index(); // new | in_progress | completed | cancelled
            $table->string('outcome')->nullable();
            $table->string('priority', 8)->default('normal');
            $table->json('history');
        });

        Schema::create('case_reviews', function (Blueprint $table) {
            $table->string('id', 64)->primary();
            $table->string('case_id', 32)->index();
            $table->string('stage', 16); // audit | medical | social | care
            $table->string('decision');
            $table->string('by');
            $table->string('by_id', 64);
            $table->dateTime('at', 3);
            $table->json('fields');
        });
    }

    public function down(): void
    {
        foreach (['case_reviews', 'case_tasks', 'case_documents', 'case_comments', 'case_activities', 'cases'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
