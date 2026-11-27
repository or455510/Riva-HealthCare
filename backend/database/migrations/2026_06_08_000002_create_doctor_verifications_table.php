<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'doctor_verified_at')) {
                $table->timestamp('doctor_verified_at')->nullable()->after('email_verified_at');
            }

            if (! Schema::hasColumn('users', 'verification_status')) {
                $table->string('verification_status')->default('not_submitted')->after('doctor_verified_at');
            }
        });

        if (! Schema::hasTable('doctor_verifications')) {
            Schema::create('doctor_verifications', function (Blueprint $table) {
                $table->id();
                $table->foreignId('doctor_id')->constrained('doctors')->cascadeOnDelete();
                $table->string('national_id_file');
                $table->string('medical_license_file');
                $table->string('syndicate_card_file');
                $table->string('certificate_file')->nullable();
                $table->string('medical_license_number')->nullable();
                $table->string('syndicate_number')->nullable();
                $table->string('specialization')->nullable();
                $table->string('clinic_or_hospital_name')->nullable();
                $table->unsignedInteger('years_of_experience')->nullable();
                $table->enum('status', ['not_submitted', 'pending', 'approved', 'rejected'])->default('pending');
                $table->text('rejection_reason')->nullable();
                $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('reviewed_at')->nullable();
                $table->timestamps();

                $table->index(['doctor_id', 'status']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('doctor_verifications');

        Schema::table('users', function (Blueprint $table) {
            foreach (['verification_status', 'doctor_verified_at'] as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
