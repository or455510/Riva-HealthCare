<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\Clinic;
use App\Models\ClinicUser;
use Illuminate\Http\Request;

/**
 * Phase C (Queue + Appointment operational workflow, principal-engineering
 * directive): the first real consumer of the Slice 3 RBAC permission model
 * (ClinicUser::PERMISSION_MANAGE_QUEUE) and the first clinic-scoped view of
 * Appointment data. Deliberately built as its own controller rather than
 * folded into AppointmentController — AppointmentController's existing
 * authorization is relationship-based (a doctor sees only their own
 * appointments, a patient only their own) by explicit, tested, documented
 * design (see CrossClinicResourceIsolationTest's docblock: clinic
 * co-membership must never be treated as sufficient authorization for
 * medical data). Queue management is a different, operational concern: a
 * receptionist needs to see and advance TODAY's appointments for every
 * doctor in their clinic, which is intentionally clinic-scoped, not
 * relationship-scoped. Exposing only the operational fields needed to run a
 * front desk (name, time, status) — never diagnoses, prescriptions, or other
 * medical content — keeps this from becoming a medical-data leak dressed up
 * as an operational feature.
 *
 * State machine (queue_status): null -> waiting -> called -> in_service ->
 * completed, with no_show/skipped as alternate terminal states reachable
 * from waiting/called, and remove() as an explicit escape hatch back to null
 * from any state (see Appointment's queue migration docblock for why this is
 * modeled as fields on Appointment rather than a separate table).
 */
class QueueController extends Controller
{
    /**
     * View is intentionally available to every active clinic member
     * (including a doctor who has no explicit queue.manage grant, and any
     * staff member regardless of their specific permissions) — a doctor and
     * every staff member need to see the day's queue to do their job; only
     * *advancing* the queue is permission-gated. EnsureClinicContext already
     * guarantees the caller has an active membership before this action
     * runs at all.
     */
    public function index(Request $request)
    {
        $clinic = $this->currentClinic($request);
        $doctorUserIds = $this->clinicDoctorUserIds($clinic);

        $appointments = Appointment::with(['patient.user', 'doctor.user'])
            ->whereHas('doctor', fn ($q) => $q->whereIn('user_id', $doctorUserIds))
            ->whereDate('appointment_date', now()->toDateString())
            ->where('status', '!=', 'cancelled')
            ->orderByRaw("CASE
                WHEN queue_status = 'in_service' THEN 0
                WHEN queue_status = 'called' THEN 1
                WHEN queue_status = 'waiting' THEN 2
                WHEN queue_status IS NULL THEN 3
                ELSE 4 END")
            ->orderBy('queue_position')
            ->orderBy('appointment_time')
            ->get();

        return $this->success($appointments->map(fn (Appointment $a) => $this->serialize($a)));
    }

    public function checkIn(Request $request, Appointment $appointment)
    {
        $clinic = $this->authorizeManage($request, $appointment);

        if ($appointment->status === 'cancelled') {
            return $this->error('Cancelled appointments cannot be checked in.', 422);
        }

        if ($appointment->appointment_date !== now()->toDateString()) {
            return $this->error('Only appointments scheduled for today can be checked into the queue.', 422);
        }

        if (in_array($appointment->queue_status, ['waiting', 'called', 'in_service', 'completed'], true)) {
            return $this->error('This appointment is already in the queue.', 409);
        }

        $nextPosition = $this->nextQueuePosition($clinic);

        $appointment->update([
            'queue_status' => 'waiting',
            'queue_position' => $nextPosition,
            'checked_in_at' => now(),
            'called_at' => null,
            'queue_completed_at' => null,
        ]);

        return $this->success($this->serialize($appointment->fresh(['patient.user', 'doctor.user'])), 'Patient checked in.');
    }

    public function call(Request $request, Appointment $appointment)
    {
        $this->authorizeManage($request, $appointment);
        $this->requireQueueStatus($appointment, ['waiting']);

        $appointment->update(['queue_status' => 'called', 'called_at' => now()]);

        return $this->success($this->serialize($appointment->fresh(['patient.user', 'doctor.user'])), 'Patient called.');
    }

    public function start(Request $request, Appointment $appointment)
    {
        $this->authorizeManage($request, $appointment);
        $this->requireQueueStatus($appointment, ['waiting', 'called']);

        $appointment->update(['queue_status' => 'in_service']);

        return $this->success($this->serialize($appointment->fresh(['patient.user', 'doctor.user'])), 'Consultation started.');
    }

    public function complete(Request $request, Appointment $appointment)
    {
        $this->authorizeManage($request, $appointment);
        $this->requireQueueStatus($appointment, ['called', 'in_service']);

        $appointment->update([
            'queue_status' => 'completed',
            'queue_completed_at' => now(),
            'status' => 'completed',
        ]);

        return $this->success($this->serialize($appointment->fresh(['patient.user', 'doctor.user'])), 'Visit completed.');
    }

    public function noShow(Request $request, Appointment $appointment)
    {
        $this->authorizeManage($request, $appointment);
        $this->requireQueueStatus($appointment, ['waiting', 'called']);

        $appointment->update(['queue_status' => 'no_show', 'queue_completed_at' => now()]);

        return $this->success($this->serialize($appointment->fresh(['patient.user', 'doctor.user'])), 'Marked as no-show.');
    }

    public function skip(Request $request, Appointment $appointment)
    {
        $this->authorizeManage($request, $appointment);
        $this->requireQueueStatus($appointment, ['waiting', 'called']);

        $appointment->update(['queue_status' => 'skipped', 'queue_completed_at' => now()]);

        return $this->success($this->serialize($appointment->fresh(['patient.user', 'doctor.user'])), 'Patient skipped.');
    }

    public function remove(Request $request, Appointment $appointment)
    {
        $this->authorizeManage($request, $appointment);

        if ($appointment->queue_status === null) {
            return $this->error('This appointment is not in the queue.', 409);
        }

        $appointment->update([
            'queue_status' => null,
            'queue_position' => null,
            'checked_in_at' => null,
            'called_at' => null,
            'queue_completed_at' => null,
        ]);

        return $this->success($this->serialize($appointment->fresh(['patient.user', 'doctor.user'])), 'Removed from queue.');
    }

    private function currentClinic(Request $request): Clinic
    {
        $membership = $request->attributes->get('clinic_membership');
        abort_unless($membership, 403, 'No active clinic context.');

        return $membership->clinic;
    }

    /**
     * Advancing the queue requires the caller's ACTIVE membership in the
     * current clinic to carry queue.manage — owner/admin always pass (see
     * ClinicUser::hasPermission()), a 'staff' member only if explicitly
     * granted, and a plain 'doctor' membership never passes this check
     * (matching hasPermission()'s existing, tested rule) — a doctor can
     * VIEW the queue via index() but does not automatically get to advance
     * every other doctor's patients through it merely for being a doctor.
     */
    private function authorizeManage(Request $request, Appointment $appointment): Clinic
    {
        $clinic = $this->currentClinic($request);
        $this->assertAppointmentBelongsToClinic($appointment, $clinic);

        $membership = $request->attributes->get('clinic_membership');
        abort_unless($membership->hasPermission(ClinicUser::PERMISSION_MANAGE_QUEUE), 403, 'You do not have permission to manage the clinic queue.');

        return $clinic;
    }

    private function assertAppointmentBelongsToClinic(Appointment $appointment, Clinic $clinic): void
    {
        $doctorUserId = $appointment->doctor?->user_id;

        $belongs = $doctorUserId && ClinicUser::where('clinic_id', $clinic->id)
            ->where('user_id', $doctorUserId)
            ->where('status', 'active')
            ->exists();

        abort_unless($belongs, 404);
    }

    private function requireQueueStatus(Appointment $appointment, array $allowed): void
    {
        if (! in_array($appointment->queue_status, $allowed, true)) {
            abort(422, 'This appointment is not in a valid queue state for that action (currently: '.($appointment->queue_status ?? 'not checked in').').');
        }
    }

    /** @return array<int> the user_ids of every active-doctor-having member of this clinic */
    private function clinicDoctorUserIds(Clinic $clinic): array
    {
        return ClinicUser::where('clinic_id', $clinic->id)
            ->where('status', 'active')
            ->pluck('user_id')
            ->all();
    }

    private function nextQueuePosition(Clinic $clinic): int
    {
        $doctorUserIds = $this->clinicDoctorUserIds($clinic);

        $max = Appointment::whereHas('doctor', fn ($q) => $q->whereIn('user_id', $doctorUserIds))
            ->whereDate('appointment_date', now()->toDateString())
            ->max('queue_position');

        return ((int) $max) + 1;
    }

    private function serialize(Appointment $appointment): array
    {
        return [
            'id' => $appointment->id,
            'patient_id' => $appointment->patient_id,
            'patient_name' => $appointment->patient?->user?->name,
            'doctor_id' => $appointment->doctor_id,
            'doctor_name' => $appointment->doctor?->user?->name,
            'appointment_date' => $appointment->appointment_date,
            'appointment_time' => $appointment->appointment_time,
            'type' => $appointment->type,
            'status' => $appointment->status,
            'payment_status' => $appointment->payment_status,
            'queue_status' => $appointment->queue_status,
            'queue_position' => $appointment->queue_position,
            'checked_in_at' => optional($appointment->checked_in_at)->toISOString(),
            'called_at' => optional($appointment->called_at)->toISOString(),
            'queue_completed_at' => optional($appointment->queue_completed_at)->toISOString(),
        ];
    }
}
