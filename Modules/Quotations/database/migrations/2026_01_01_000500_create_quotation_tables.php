<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quotation_rounds', function (Blueprint $table) {
            $table->string('id', 32)->primary(); // QR-00001
            $table->string('case_id', 32)->index();
            $table->string('request_number', 32);
            $table->string('service');
            $table->string('service_id', 32)->nullable();
            $table->string('subservice')->default('');
            $table->text('specifications');
            $table->text('description');
            $table->decimal('quantity', 12, 2);
            $table->string('unit');
            $table->string('city');
            $table->string('area')->default('');
            $table->string('expected_start', 32);
            $table->dateTime('deadline');
            $table->text('provider_notes')->nullable();
            $table->json('shared_attachments');
            $table->string('status', 16)->index(); // draft | open | closed | evaluating | selected | cancelled
            $table->string('selected_quotation_id', 32)->nullable();
            $table->text('selection_reason')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->string('created_by');
            $table->string('created_by_id', 64);
            $table->json('history');
            $table->timestamps();
        });

        Schema::create('quotation_invitations', function (Blueprint $table) {
            $table->string('id', 32)->primary(); // INV-xxxx
            $table->string('quotation_round_id', 32)->index();
            $table->string('provider_id', 32)->index();
            $table->string('provider_name');
            $table->string('status', 16); // invited | draft | submitted | declined
            $table->dateTime('sent_at');
            $table->dateTime('responded_at')->nullable();
            $table->text('decline_reason')->nullable();
            $table->unique(['quotation_round_id', 'provider_id']);
        });

        Schema::create('provider_quotations', function (Blueprint $table) {
            $table->string('id', 32)->primary(); // Q-xxxx
            $table->string('number', 48);
            $table->string('quotation_round_id', 32)->index();
            $table->string('invitation_id', 32)->unique();
            $table->string('provider_id', 32)->index();
            $table->string('provider_name');
            $table->string('status', 16); // draft | submitted
            $table->unsignedInteger('version')->default(1);
            $table->text('description');
            $table->json('line_items');
            $table->string('tax_treatment', 16);
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->bigInteger('subtotal')->default(0);
            $table->bigInteger('discount_total')->default(0);
            $table->bigInteger('tax_total')->default(0);
            $table->bigInteger('grand_total')->default(0);
            $table->unsignedInteger('start_within_days')->default(0);
            $table->string('delivery_duration')->default('');
            $table->string('valid_until', 10)->default('');
            $table->text('notes')->nullable();
            $table->json('attachments');
            $table->json('evaluations');
            $table->json('branding')->nullable();
            $table->dateTime('submitted_at')->nullable();
            $table->timestamps();
        });

        Schema::create('provider_assessments', function (Blueprint $table) {
            $table->string('id', 32)->primary(); // PA-xxxx
            $table->string('invitation_id', 32)->unique();
            $table->string('quotation_round_id', 32)->index();
            $table->string('case_id', 32);
            $table->string('provider_id', 32);
            $table->string('contact_status', 16);
            $table->string('assessed_at', 10)->nullable();
            $table->string('method', 16)->nullable();
            $table->text('needs_notes')->nullable();
            $table->text('recommended_scope')->nullable();
            $table->unsignedInteger('recommended_visits')->nullable();
            $table->text('comments')->nullable();
            $table->json('history');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['provider_assessments', 'provider_quotations', 'quotation_invitations', 'quotation_rounds'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
