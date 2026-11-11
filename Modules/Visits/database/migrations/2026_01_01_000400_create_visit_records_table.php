<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Front VisitRecord. The visit plan itself lives in cases.visit_plan.
        Schema::create('visit_records', function (Blueprint $table) {
            $table->string('id', 64)->primary();
            $table->string('case_id', 32)->index();
            $table->string('assignment_id', 32)->nullable();
            $table->string('provider_id', 32)->index();
            $table->unsignedInteger('sequence');
            $table->dateTime('scheduled_at');
            $table->dateTime('completed_at')->nullable();
            $table->string('status', 16)->index(); // scheduled | completed | missed | cancelled
            $table->text('notes')->nullable();
            $table->text('evidence')->nullable();
            $table->string('created_by');
            $table->string('completed_by')->nullable();
            $table->text('missed_reason')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->json('audit');
            $table->json('corrections');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visit_records');
    }
};
