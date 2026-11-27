<?php

namespace App\Http\Controllers;

use App\Models\Doctor;
use App\Models\DoctorVerification;
use App\Services\ActivityLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DoctorVerificationController extends Controller
{
    public function __construct(private readonly ActivityLogService $activityLogService) {}

    public function status(Request $request)
    {
        $doctor = $this->doctorFor($request);
        $verification = $doctor->verification()->with(['doctor.user', 'reviewer'])->latest()->first();

        return $this->success([
            'status' => $verification?->status ?? $request->user()->verification_status ?? 'not_submitted',
            'verification' => $verification,
            'doctor' => $doctor->load('user'),
        ]);
    }

    public function submit(Request $request)
    {
        $doctor = $this->doctorFor($request);

        $data = $request->validate([
            'national_id_file' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
            'medical_license_file' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
            'syndicate_card_file' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
            'certificate_file' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
            'medical_license_number' => ['nullable', 'string', 'max:255'],
            'syndicate_number' => ['nullable', 'string', 'max:255'],
            'specialization' => ['nullable', 'string', 'max:255'],
            'clinic_or_hospital_name' => ['nullable', 'string', 'max:255'],
            'years_of_experience' => ['nullable', 'integer', 'min:0', 'max:80'],
        ]);

        DB::beginTransaction();

        try {
            foreach (['national_id_file', 'medical_license_file', 'syndicate_card_file', 'certificate_file'] as $field) {
                if ($request->hasFile($field)) {
                    $data[$field] = $request->file($field)->store('doctor-verifications', 'public');
                }
            }

            $verification = DoctorVerification::updateOrCreate(
                ['doctor_id' => $doctor->id],
                array_merge($data, [
                    'status' => 'pending',
                    'rejection_reason' => null,
                    'reviewed_by' => null,
                    'reviewed_at' => null,
                ])
            );

            $doctor->update([
                'specialty' => $data['specialization'] ?? $doctor->specialty,
                'license_number' => $data['medical_license_number'] ?? $doctor->license_number,
                'years_of_experience' => $data['years_of_experience'] ?? $doctor->years_of_experience,
                'verification_status' => 'pending',
                'verified_at' => null,
                'is_verified' => false,
                'rejection_reason' => null,
            ]);

            $request->user()->update([
                'verification_status' => 'pending',
                'doctor_verified_at' => null,
            ]);

            $this->activityLogService->log($request->user(), 'doctor.verification.submitted', 'Doctor verification documents submitted', $request);

            DB::commit();

            Log::info('Doctor verification submitted', [
                'doctor_id' => $doctor->id,
                'verification_id' => $verification->id,
            ]);

            return $this->success($verification->fresh(['doctor.user']), 'Your verification is pending admin review.', 201);
        } catch (\Throwable $exception) {
            DB::rollBack();
            Log::error('Doctor verification submission failed', [
                'doctor_id' => $doctor->id,
                'error' => $exception->getMessage(),
            ]);

            return $this->error('Unable to submit verification documents.', 500);
        }
    }

    private function doctorFor(Request $request): Doctor
    {
        $doctor = $request->user()->doctor ?: Doctor::create(['user_id' => $request->user()->id]);

        abort_unless($request->user()->role === 'doctor', 403);

        return $doctor;
    }
}
