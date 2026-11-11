<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('services', function (Blueprint $table) {
            $table->string('id', 32)->primary(); // SRV-001
            $table->string('name');
            $table->string('name_en')->nullable();
            $table->string('category');
            $table->text('description');
            $table->string('status', 16)->default('active');
            $table->boolean('requires_medical_report')->default(false);
            $table->boolean('requires_documents')->default(false);
            $table->boolean('allow_internal')->default(true);
            $table->boolean('allow_external')->default(true);
            $table->boolean('public_visible')->nullable();
            $table->boolean('portal_visible')->nullable();
            $table->integer('sort_order')->nullable();
            $table->json('subservices')->nullable();
            $table->json('requirements')->nullable();
            $table->string('delivery_type', 32)->nullable(); // one_time | multiple_visits
            $table->unsignedInteger('default_visit_count')->nullable();
            $table->timestamps();
        });

        Schema::create('providers', function (Blueprint $table) {
            $table->string('id', 32)->primary(); // PRV-0001
            $table->string('name');
            $table->string('type');
            $table->string('license')->default('');
            $table->string('registry')->default('');
            $table->string('phone')->default('');
            $table->string('email')->default('');
            $table->string('city')->default('');
            $table->string('district')->default('');
            $table->string('address')->default('');
            $table->json('contact')->nullable();
            $table->string('status', 16)->default('active');
            $table->text('notes')->nullable();
            $table->longText('logo')->nullable();
            $table->timestamps();
        });

        Schema::create('provider_service', function (Blueprint $table) {
            $table->string('provider_id', 32);
            $table->string('service_id', 32);
            $table->dateTime('added_at');
            $table->primary(['provider_id', 'service_id']);
            $table->foreign('provider_id')->references('id')->on('providers')->cascadeOnDelete();
            $table->foreign('service_id')->references('id')->on('services')->cascadeOnDelete();
        });

        Schema::create('provider_notes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('provider_id', 32)->index();
            $table->dateTime('at');
            $table->text('text');
            $table->string('author');
            $table->string('author_id', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_notes');
        Schema::dropIfExists('provider_service');
        Schema::dropIfExists('providers');
        Schema::dropIfExists('services');
    }
};
