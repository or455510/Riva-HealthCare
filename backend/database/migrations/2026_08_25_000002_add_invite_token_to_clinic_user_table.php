<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RIVA-143: real invite-link flow for clinic staff. Previously
 * ClinicMembershipController::store() required `exists:users,email` — a
 * clinic owner could only invite someone who already had a Riva account,
 * which made inviting a brand-new secretary/receptionist impossible. Now a
 * pending membership for a not-yet-registered email gets a real,
 * single-use, expiring token here; the invitee visits a link built from it,
 * sets their own password, and becomes an active member — never shown the
 * public role selector (see AuthController::register()'s role enum, which
 * intentionally has no 'staff' option).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clinic_user', function (Blueprint $table) {
            $table->string('invite_token')->nullable()->unique()->after('joined_at');
            $table->timestamp('invite_token_expires_at')->nullable()->after('invite_token');
        });
    }

    public function down(): void
    {
        Schema::table('clinic_user', function (Blueprint $table) {
            $table->dropColumn(['invite_token', 'invite_token_expires_at']);
        });
    }
};
