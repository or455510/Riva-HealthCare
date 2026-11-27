<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RIVA audit (Section 2 — Doctor public credentials/certificates,
 * 2026-08-28): a Doctor's PUBLIC professional credentials (degrees,
 * certificates, training) are architecturally distinct from the PRIVATE
 * verification documents in `doctor_verifications` (national ID, medical
 * license, syndicate card) — no existing table covered this before this
 * migration; confirmed via a full audit of app/Models that no
 * certificate/credential model existed anywhere in this codebase.
 *
 * `is_public` defaults to false: a credential a doctor adds is private by
 * default until they explicitly choose to display it, matching the
 * requirement that not every uploaded document should automatically become
 * publicly visible. `file_path`, when set, is stored on the PUBLIC disk
 * (same mechanism already used for `users.profile_image`) — deliberately
 * NOT the signed-URL flow `doctor_verifications` uses, because a credential
 * file is only ever accepted here once the doctor has already marked it
 * public; there is no private-file state to protect for this table the way
 * there is for verification documents.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('doctor_credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('doctor_id')->constrained('doctors')->cascadeOnDelete();
            $table->string('title');
            $table->enum('credential_type', [
                'bachelor', 'master', 'phd', 'certificate', 'training', 'professional_license', 'other',
            ])->default('other');
            $table->string('issuing_organization')->nullable();
            $table->unsignedSmallInteger('year')->nullable();
            $table->text('description')->nullable();
            $table->string('file_path')->nullable();
            $table->boolean('is_public')->default(false);
            $table->timestamps();

            $table->index(['doctor_id', 'is_public']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('doctor_credentials');
    }
};
