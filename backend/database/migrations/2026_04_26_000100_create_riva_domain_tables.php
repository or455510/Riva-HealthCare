<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->date('date_of_birth')->nullable();
            $table->unsignedInteger('age')->nullable();
            $table->string('gender')->nullable();
            $table->string('blood_type')->nullable();
            $table->string('emergency_contact')->nullable();
            $table->text('chronic_conditions')->nullable();
            $table->text('medical_history')->nullable();
            $table->timestamps();
        });

        Schema::create('doctors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('specialty')->nullable();
            $table->decimal('salary', 10, 2)->nullable();
            $table->unsignedInteger('years_of_experience')->nullable();
            $table->decimal('fee', 10, 2)->nullable();
            $table->text('bio')->nullable();
            $table->string('license_number')->nullable();
            $table->enum('verification_status', ['pending', 'verified', 'rejected'])->default('pending');
            $table->boolean('is_active')->default(true);
            $table->string('contact_info')->nullable();
            $table->text('about')->nullable();
            $table->string('available_days')->nullable();
            $table->timestamps();
        });

        Schema::create('caregivers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('specialty')->nullable();
            $table->unsignedInteger('experience_years')->nullable();
            $table->decimal('salary', 10, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->enum('verification_status', ['pending', 'verified', 'rejected'])->default('pending');
            $table->text('about')->nullable();
            $table->timestamps();
        });

        Schema::create('patient_doctor_relationships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained('patients')->cascadeOnDelete();
            $table->foreignId('doctor_id')->constrained('doctors')->cascadeOnDelete();
            $table->enum('status', ['pending', 'active', 'rejected', 'ended'])->default('pending');
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();
        });

        Schema::create('patient_caregiver_relationships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained('patients')->cascadeOnDelete();
            $table->foreignId('caregiver_id')->constrained('caregivers')->cascadeOnDelete();
            $table->enum('status', ['pending', 'active', 'rejected', 'ended'])->default('pending');
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();
        });

        Schema::create('medications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained('patients')->cascadeOnDelete();
            $table->string('name');
            $table->string('dosage');
            $table->time('schedule_time')->nullable();
            $table->string('frequency')->default('daily');
            $table->text('instructions')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('medication_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medication_id')->constrained('medications')->cascadeOnDelete();
            $table->foreignId('patient_id')->constrained('patients')->cascadeOnDelete();
            $table->dateTime('scheduled_at');
            $table->dateTime('taken_at')->nullable();
            $table->enum('status', ['pending', 'taken', 'missed', 'snoozed', 'skipped', 'need_help'])->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('daily_statuses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained('patients')->cascadeOnDelete();
            $table->string('mood')->nullable();
            $table->unsignedInteger('pain_level')->default(0);
            $table->string('sleep_quality')->nullable();
            $table->text('symptoms')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('medication_taken')->default(false);
            $table->enum('risk_level', ['stable', 'attention_needed', 'moderate_risk', 'high_risk'])->default('stable');
            $table->timestamps();
        });

        Schema::create('user_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->string('title');
            $table->text('message');
            $table->string('severity')->default('info');
            $table->boolean('is_read')->default(false);
            $table->string('related_type')->nullable();
            $table->unsignedBigInteger('related_id')->nullable();
            $table->timestamps();
        });

        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sender_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('receiver_id')->constrained('users')->cascadeOnDelete();
            $table->text('body');
            $table->boolean('is_read')->default(false);
            $table->timestamps();
        });

        Schema::create('reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained('patients')->cascadeOnDelete();
            $table->foreignId('doctor_id')->constrained('doctors')->cascadeOnDelete();
            $table->string('title');
            $table->text('summary');
            $table->text('final_report')->nullable();
            $table->timestamps();
        });

        Schema::create('diagnoses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained('patients')->cascadeOnDelete();
            $table->foreignId('doctor_id')->constrained('doctors')->cascadeOnDelete();
            $table->string('title');
            $table->text('details');
            $table->timestamps();
        });

        Schema::create('diseases', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('slug')->unique();
            $table->text('overview')->nullable();
            $table->text('symptoms')->nullable();
            $table->text('causes')->nullable();
            $table->text('treatment_info')->nullable();
            $table->text('tips')->nullable();
            $table->string('image')->nullable();
            $table->timestamps();
        });

        Schema::create('hospitals', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('address')->nullable();
            $table->string('phone')->nullable();
            $table->timestamps();
        });

        Schema::create('appointments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained('patients')->cascadeOnDelete();
            $table->foreignId('doctor_id')->constrained('doctors')->cascadeOnDelete();
            $table->foreignId('hospital_id')->nullable()->constrained('hospitals')->nullOnDelete();
            $table->date('appointment_date');
            $table->time('appointment_time');
            $table->string('type')->nullable();
            $table->decimal('amount', 10, 2)->default(0);
            $table->enum('payment_status', ['unpaid', 'paid', 'failed'])->default('unpaid');
            $table->enum('status', ['pending_payment', 'pending', 'confirmed', 'cancelled', 'completed'])->default('pending_payment');
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('appointment_booking_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('appointment_id')->constrained('appointments')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('action');
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('doctor_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('doctor_id')->constrained('doctors')->cascadeOnDelete();
            $table->foreignId('patient_id')->constrained('patients')->cascadeOnDelete();
            $table->unsignedTinyInteger('rating');
            $table->text('comment')->nullable();
            $table->timestamps();
        });

        Schema::create('doctor_availabilities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('doctor_id')->constrained('doctors')->cascadeOnDelete();
            $table->string('day_of_week');
            $table->time('start_time');
            $table->time('end_time');
            $table->boolean('is_available')->default(true);
            $table->timestamps();
        });

        Schema::create('doctor_hospitals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('doctor_id')->constrained('doctors')->cascadeOnDelete();
            $table->foreignId('hospital_id')->constrained('hospitals')->cascadeOnDelete();
            $table->timestamps();
        });

        Schema::create('system_activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action');
            $table->text('description')->nullable();
            $table->string('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('login_attempts', function (Blueprint $table) {
            $table->id();
            $table->string('email');
            $table->string('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->boolean('successful')->default(false);
            $table->string('reason')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('login_attempts');
        Schema::dropIfExists('system_activity_logs');
        Schema::dropIfExists('doctor_hospitals');
        Schema::dropIfExists('doctor_availabilities');
        Schema::dropIfExists('doctor_reviews');
        Schema::dropIfExists('appointment_booking_logs');
        Schema::dropIfExists('appointments');
        Schema::dropIfExists('hospitals');
        Schema::dropIfExists('diseases');
        Schema::dropIfExists('diagnoses');
        Schema::dropIfExists('reports');
        Schema::dropIfExists('messages');
        Schema::dropIfExists('user_notifications');
        Schema::dropIfExists('daily_statuses');
        Schema::dropIfExists('medication_logs');
        Schema::dropIfExists('medications');
        Schema::dropIfExists('patient_caregiver_relationships');
        Schema::dropIfExists('patient_doctor_relationships');
        Schema::dropIfExists('caregivers');
        Schema::dropIfExists('doctors');
        Schema::dropIfExists('patients');
    }
};
