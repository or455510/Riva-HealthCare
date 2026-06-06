<?php

namespace App\Http\Controllers;

use App\Models\Caregiver;
use App\Models\Doctor;
use App\Models\PatientCaregiverRelationship;
use App\Models\PatientDoctorRelationship;
use App\Services\ActivityLogService;
use App\Services\NotificationService;
use Illuminate\Http\Request;

class RelationshipController extends Controller
{
    public function __construct(
        private readonly NotificationService $notificationService,
        private readonly ActivityLogService $activityLogService
    ) {
    }

    public function sendDoctorFollowRequest(Request $request, Doctor $doctor)
    {
        $user = $request->user();
        abort_unless($user->role === 'patient' && $user->patient, 403);

        $data = $request->validate([
            'payment_method' => ['nullable', 'string'],
            'payment_mode' => ['nullable', 'string'],
            'billing_email' => ['nullable', 'email'],
            'billing_address' => ['nullable', 'string'],
            'appointment_date' => ['nullable', 'date'],
            'appointment_time' => ['nullable', 'date_format:H:i'],
            'type' => ['nullable', 'string'],
            'amount' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
        ]);

        $existing = PatientDoctorRelationship::query()
            ->where('patient_id', $user->patient->id)
            ->where('doctor_id', $doctor->id)
            ->whereIn('status', ['pending', 'active'])
            ->latest()
            ->first();

        if ($existing) {
            return $this->success($existing, 'Follow request already exists');
        }

        $relationship = PatientDoctorRelationship::create([
            'patient_id' => $user->patient->id,
            'doctor_id' => $doctor->id,
            'status' => 'pending',
            'requested_at' => now(),
        ]);

        if ($doctor->user) {
            $this->notificationService->create(
                $doctor->user,
                'follow_request',
                'New patient follow request',
                $user->name.' sent you a follow request.',
                'info',
                PatientDoctorRelationship::class,
                $relationship->id
            );
        }

        $this->activityLogService->log($user, 'doctor.follow_request.sent', 'Patient sent doctor follow request', $request);

        return response()->json([
            'success' => true,
            'message' => 'Follow request sent successfully',
            'data' => $relationship,
        ], 201);
    }

    public function doctorFollowRequests(Request $request)
    {
        $doctor = $request->user()->doctor;
        abort_unless($doctor, 403);

        $requests = PatientDoctorRelationship::with('patient.user')
            ->where('doctor_id', $doctor->id)
            ->latest()
            ->get();

        return $this->success($requests);
    }

    public function acceptDoctorFollowRequest(Request $request, PatientDoctorRelationship $relationship)
    {
        $doctor = $request->user()->doctor;
        abort_unless($doctor && $relationship->doctor_id === $doctor->id, 403);

        PatientDoctorRelationship::query()
            ->where('patient_id', $relationship->patient_id)
            ->where('status', 'active')
            ->update([
                'status' => 'ended',
                'ended_at' => now(),
            ]);

        $relationship->update([
            'status' => 'active',
            'responded_at' => now(),
        ]);

        $patientUser = $relationship->patient?->user;
        if ($patientUser) {
            $this->notificationService->create(
                $patientUser,
                'follow_request_accepted',
                'Doctor follow request accepted',
                $doctor->user?->name.' accepted your follow request.',
                'success',
                PatientDoctorRelationship::class,
                $relationship->id
            );
        }

        $this->activityLogService->log($request->user(), 'doctor.follow_request.accepted', 'Doctor accepted follow request', $request);

        return $this->success($relationship->fresh(['patient.user', 'doctor.user']), 'Follow request accepted');
    }

    public function rejectDoctorFollowRequest(Request $request, PatientDoctorRelationship $relationship)
    {
        $doctor = $request->user()->doctor;
        abort_unless($doctor && $relationship->doctor_id === $doctor->id, 403);

        $relationship->update([
            'status' => 'rejected',
            'responded_at' => now(),
        ]);

        if ($relationship->patient?->user) {
            $this->notificationService->create(
                $relationship->patient->user,
                'follow_request_rejected',
                'Doctor follow request rejected',
                $doctor->user?->name.' rejected your follow request.',
                'warning',
                PatientDoctorRelationship::class,
                $relationship->id
            );
        }

        return $this->success($relationship, 'Follow request rejected');
    }

    public function doctorPatients(Request $request)
    {
        $doctor = $request->user()->doctor;
        abort_unless($doctor, 403);

        $relationships = PatientDoctorRelationship::with('patient.user')
            ->where('doctor_id', $doctor->id)
            ->latest()
            ->get();

        return $this->success($relationships);
    }

    public function patientDoctors(Request $request)
    {
        $patient = $request->user()->patient;
        abort_unless($patient, 403);

        $relationships = PatientDoctorRelationship::with('doctor.user')
            ->where('patient_id', $patient->id)
            ->where('status', 'active')
            ->latest()
            ->get();

        return $this->success($relationships);
    }

    public function sendCaregiverFollowRequest(Request $request, Caregiver $caregiver)
    {
        $patient = $request->user()->patient;
        abort_unless($patient, 403);

        $relationship = PatientCaregiverRelationship::query()
            ->where('patient_id', $patient->id)
            ->where('caregiver_id', $caregiver->id)
            ->latest()
            ->first();

        if ($relationship && in_array($relationship->status, ['pending', 'active', 'accepted'], true)) {
            return $this->success($relationship, 'Follow request already exists');
        }

        if ($relationship) {
            $relationship->update([
                'status' => 'pending',
                'requested_at' => now(),
                'responded_at' => null,
                'ended_at' => null,
            ]);
        } else {
            $relationship = PatientCaregiverRelationship::create([
                'patient_id' => $patient->id,
                'caregiver_id' => $caregiver->id,
                'status' => 'pending',
                'requested_at' => now(),
            ]);
        }

        if ($caregiver->user) {
            $this->notificationService->create(
                $caregiver->user,
                'caregiver_follow_request',
                'New caregiver follow request',
                $request->user()->name.' sent you a caregiver follow request.',
                'info',
                PatientCaregiverRelationship::class,
                $relationship->id
            );
        }

        return $this->success($relationship, 'Follow request sent successfully', 201);
    }

    public function patientCaregivers(Request $request)
    {
        $patient = $request->user()->patient;
        abort_unless($patient, 403);

        $relationships = PatientCaregiverRelationship::with('caregiver.user')
            ->where('patient_id', $patient->id)
            ->whereIn('status', ['active', 'accepted'])
            ->latest()
            ->get();

        return $this->success($relationships);
    }

    public function caregiverFollowRequests(Request $request)
    {
        $caregiver = $request->user()->caregiver;
        abort_unless($caregiver, 403);

        $requests = PatientCaregiverRelationship::with('patient.user')
            ->where('caregiver_id', $caregiver->id)
            ->latest()
            ->get();

        return $this->success($requests);
    }

    public function acceptCaregiverFollowRequest(Request $request, PatientCaregiverRelationship $relationship)
    {
        $caregiver = $request->user()->caregiver;
        abort_unless($caregiver && $relationship->caregiver_id === $caregiver->id, 403);

        $relationship->update([
            'status' => 'active',
            'responded_at' => now(),
        ]);

        if ($relationship->patient?->user) {
            $this->notificationService->create(
                $relationship->patient->user,
                'caregiver_follow_request_accepted',
                'Caregiver follow request accepted',
                $caregiver->user?->name.' accepted your follow request.',
                'success',
                PatientCaregiverRelationship::class,
                $relationship->id
            );
        }

        return $this->success($relationship, 'Follow request accepted');
    }

    public function rejectCaregiverFollowRequest(Request $request, PatientCaregiverRelationship $relationship)
    {
        $caregiver = $request->user()->caregiver;
        abort_unless($caregiver && $relationship->caregiver_id === $caregiver->id, 403);

        $relationship->update([
            'status' => 'rejected',
            'responded_at' => now(),
        ]);

        return $this->success($relationship, 'Follow request rejected');
    }
}
