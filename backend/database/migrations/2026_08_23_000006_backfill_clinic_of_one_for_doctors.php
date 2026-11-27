<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * PHASE 1 (Identity + Tenancy) data migration: "solo doctor = clinic-of-one."
 * Every existing Doctor gets a Clinic (named after them) with themselves as the
 * sole 'owner' member. This is deliberately a DATA migration, not just schema —
 * it is the actual migration strategy for moving today's doctor-owns-their-
 * patients model into the clinic-tenancy model without losing or altering any
 * existing clinical data. No existing table is modified; this only inserts new
 * clinics/clinic_user rows.
 *
 * Idempotent: safe to run more than once (e.g. after a failed partial run) —
 * skips any doctor who already has a clinic_user row. Uses the query builder
 * directly rather than Eloquent models, per this migration's own point-in-time
 * schema, so it keeps working even if the Clinic/User models change shape later.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('clinics') || ! Schema::hasTable('clinic_user') || ! Schema::hasTable('doctors')) {
            return;
        }

        $doctors = DB::table('doctors')
            ->join('users', 'users.id', '=', 'doctors.user_id')
            ->select('doctors.id as doctor_id', 'doctors.user_id as user_id', 'users.name as user_name', 'users.first_name as first_name')
            ->get();

        $now = now();

        foreach ($doctors as $doctor) {
            $alreadyMember = DB::table('clinic_user')
                ->where('user_id', $doctor->user_id)
                ->exists();

            if ($alreadyMember) {
                continue;
            }

            $displayName = trim((string) ($doctor->user_name ?: $doctor->first_name ?: 'Doctor'));
            $clinicName = $displayName !== '' ? "{$displayName}'s Practice" : "Doctor #{$doctor->doctor_id}'s Practice";
            $slug = Str::slug($clinicName.'-'.$doctor->doctor_id);

            $clinicId = DB::table('clinics')->insertGetId([
                'name' => $clinicName,
                'slug' => $slug,
                'owner_user_id' => $doctor->user_id,
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('clinic_user')->insert([
                'clinic_id' => $clinicId,
                'user_id' => $doctor->user_id,
                'role' => 'owner',
                'status' => 'active',
                'joined_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        // Deliberately no-op: reversing this would delete clinics that may by then
        // have real staff/data attached to them beyond what this migration created.
        // Rolling back tenancy, if ever needed, must be a deliberate, reviewed,
        // separate operation — not an automatic side effect of migrate:rollback.
    }
};
