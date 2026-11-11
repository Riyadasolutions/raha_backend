<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Core (non-module) tables: accounts, RBAC primitives, sessions, security/audit logs,
 * notifications, settings and id sequences. Modules build on top of these.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->string('id', 64)->primary();
            $table->string('account_type', 16)->index(); // STAFF | PROVIDER | BENEFICIARY
            $table->string('name');
            $table->string('role', 32)->default('team'); // primary workflow role (front Role)
            $table->string('role_label')->default('');
            $table->string('department')->default('');
            $table->string('initials', 8)->default('');
            $table->string('beneficiary_id', 32)->nullable()->unique();
            $table->string('provider_id', 32)->nullable()->index();
            $table->string('username', 64)->nullable()->unique();
            $table->string('email')->nullable()->unique();
            $table->string('national_id', 32)->nullable()->unique(); // beneficiary login identifier
            $table->string('mobile', 32)->default('');
            $table->string('job_title')->default('');
            $table->string('password');
            $table->json('password_history')->nullable();
            $table->boolean('active')->default(true);
            $table->boolean('suspended')->default(false);
            $table->dateTime('locked_at')->nullable();
            $table->dateTime('locked_until')->nullable();
            $table->unsignedInteger('failed_attempts')->default(0);
            $table->boolean('must_change_password')->default(false);
            $table->dateTime('password_changed_at')->nullable();
            $table->boolean('mfa_enabled')->default(false);
            $table->dateTime('last_login_at')->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->timestamps();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->dateTime('created_at')->nullable();
        });

        Schema::create('otp_codes', function (Blueprint $table) {
            $table->id();
            $table->string('user_id', 64)->index();
            $table->string('purpose', 32);
            $table->string('code_hash');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->dateTime('expires_at');
            $table->dateTime('used_at')->nullable();
            $table->timestamps();
        });

        Schema::create('roles', function (Blueprint $table) {
            $table->string('id', 64)->primary();
            $table->string('code', 64)->unique();
            $table->string('name_ar')->unique();
            $table->string('name_en')->default('');
            $table->text('description')->nullable();
            $table->string('type', 16)->default('CUSTOM'); // SYSTEM | CUSTOM
            $table->string('status', 16)->default('active');
            $table->json('permissions'); // code => scope
            $table->json('migrations')->nullable();
            $table->string('workflow_role', 32)->nullable();
            $table->string('updated_by')->nullable();
            $table->timestamps();
        });

        Schema::create('role_user', function (Blueprint $table) {
            $table->string('user_id', 64);
            $table->string('role_id', 64);
            $table->unsignedSmallInteger('position')->default(0);
            $table->primary(['user_id', 'role_id']);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('role_id')->references('id')->on('roles')->cascadeOnDelete();
        });

        Schema::create('user_sessions', function (Blueprint $table) {
            $table->string('id', 64)->primary();
            $table->string('user_id', 64)->index();
            $table->unsignedBigInteger('token_id')->nullable()->index();
            $table->string('user_name');
            $table->string('device')->default('');
            $table->string('browser')->default('');
            $table->string('ip', 64)->default('');
            $table->dateTime('started_at');
            $table->dateTime('last_active_at');
            $table->string('status', 16)->default('active'); // active | terminated
            $table->string('ended_reason')->nullable();
        });

        Schema::create('security_policies', function (Blueprint $table) {
            $table->id();
            $table->json('policy');
            $table->timestamps();
        });

        Schema::create('security_events', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->dateTime('at')->index();
            $table->string('type', 48)->index();
            $table->string('result', 16)->default('SUCCESS');
            $table->string('user_id', 64)->nullable()->index();
            $table->string('user_name');
            $table->string('ip', 64)->default('');
            $table->string('device')->default('');
            $table->string('by');
            $table->string('target')->nullable();
            $table->json('details')->nullable();
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->dateTime('at')->index();
            $table->string('user_id', 64)->nullable()->index();
            $table->string('user');
            $table->string('role')->default('');
            $table->string('action');
            $table->string('record_type', 64)->index();
            $table->string('record_id', 64)->index();
            $table->string('ip', 64)->nullable();
            $table->json('changes')->nullable();
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->dateTime('at')->index();
            $table->string('title');
            $table->text('body');
            $table->json('roles'); // workflow roles that receive it
            $table->string('user_id', 64)->nullable()->index();
            $table->string('provider_id', 32)->nullable()->index();
            $table->string('beneficiary_id', 32)->nullable()->index();
            $table->string('case_id', 32)->nullable()->index();
            $table->json('link');
        });

        Schema::create('notification_reads', function (Blueprint $table) {
            $table->string('notification_id', 32);
            $table->string('user_id', 64);
            $table->dateTime('read_at');
            $table->primary(['notification_id', 'user_id']);
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->string('key', 64)->primary();
            $table->json('value');
            $table->string('updated_by')->nullable();
            $table->timestamps();
        });

        Schema::create('sequences', function (Blueprint $table) {
            $table->string('key', 64)->primary();
            $table->unsignedBigInteger('value')->default(0);
        });

        Schema::create('uploads', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('user_id', 64)->index();
            $table->string('name');
            $table->string('mime', 128);
            $table->string('type', 8); // PDF | JPG | PNG
            $table->unsignedBigInteger('size');
            $table->string('path');
            $table->dateTime('attached_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['uploads', 'sequences', 'settings', 'notification_reads', 'notifications', 'audit_logs', 'security_events', 'security_policies', 'user_sessions', 'role_user', 'roles', 'otp_codes', 'password_reset_tokens', 'users'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
