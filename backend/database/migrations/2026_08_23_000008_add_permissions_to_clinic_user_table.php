<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase B (RBAC / staff permissions, principal-engineering directive): role
 * alone (owner/admin/doctor/staff) is too coarse for reception/secretary staff
 * — "staff" needs to cover people who can register patients and manage the
 * queue WITHOUT automatically being able to touch medical records or
 * prescriptions, which a single role name can't express. This column holds an
 * explicit, per-member allowlist of operational capabilities (see
 * ClinicUser::PERMISSION_* / hasPermission()); owner and admin remain full-
 * access by role regardless of this column, so it only meaningfully restricts
 * the 'staff' role.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('clinic_user', 'permissions')) {
            Schema::table('clinic_user', function (Blueprint $table) {
                $table->json('permissions')->nullable()->after('role');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('clinic_user', 'permissions')) {
            Schema::table('clinic_user', function (Blueprint $table) {
                $table->dropColumn('permissions');
            });
        }
    }
};
