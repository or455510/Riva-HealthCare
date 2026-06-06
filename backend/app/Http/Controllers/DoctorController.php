<?php

namespace App\Http\Controllers;

use App\Models\Doctor;
use App\Models\DoctorAvailability;
use App\Models\DoctorReview;
use App\Models\PatientDoctorRelationship;
use App\Services\ActivityLogService;
use App\Services\NotificationService;
use Illuminate\Http\Request;

class DoctorController extends Controller
{
    public function __construct(
        private readonly NotificationService $notificationService,
        private readonly ActivityLogService $activityLogService
    ) {
    }

    public function index(Request $request)
    {
        $doctors = Doctor::with(['user', 'reviews', 'availabilities'])
            ->where('is_active', true)
            ->get()
            ->map(fn (Doctor $doctor) => $this->transformDoctor($doctor, $request->user()));

        return $this->success($doctors);
    }

    public function show(Request $request, Doctor $doctor)
    {
        $doctor->load(['user', 'reviews.patient.user', 'availabilities', 'hospitals']);
        return $this->success($this->transformDoctor($doctor, $request->user(), true));
    }

    public function reviews(Doctor $doctor)
    {
        $reviews = $doctor->reviews()->with('patient.user')->latest()->get()->map(function (DoctorReview $review) {
            return [
                'id' => $review->id,
                'rating' => $review->rating,
                'comment' => $review->comment,
                'patient_name' => $review->patient?->user?->name ?? 'Patient',
                'created_at' => $review->created_at,
            ];
        });

        return $this->success($reviews);
    }

    public function storeReview(Request $request, Doctor $doctor)
    {
        $user = $request->user();
        abort_unless($user->role === 'patient' && $user->patient, 403);

        $hasActiveRelationship = PatientDoctorRelationship::query()
            ->where('patient_id', $user->patient->id)
            ->where('doctor_id', $doctor->id)
            ->whereIn('status', ['active', 'accepted'])
            ->exists();

        if (!$hasActiveRelationship) {
            return $this->error('You can review this doctor after your follow request is accepted.', 403);
        }

        $data = $request->validate([
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'comment' => ['nullable', 'string'],
        ]);

        $review = DoctorReview::create([
            'doctor_id' => $doctor->id,
            'patient_id' => $user->patient->id,
            'rating' => $data['rating'],
            'comment' => $data['comment'] ?? '',
        ]);

        $this->activityLogService->log($user, 'doctor.review.created', 'Doctor review submitted', $request);

        return $this->success($review, 'Review submitted successfully', 201);
    }

    public function availability(Doctor $doctor)
    {
        $availability = $doctor->availabilities()->orderBy('day_of_week')->get();
        return $this->success($availability);
    }

    public function hospitals(Doctor $doctor)
    {
        return $this->success($doctor->hospitals()->get());
    }

    private function transformDoctor(Doctor $doctor, $viewer = null, bool $full = false): array
    {
        $doctor->loadMissing(['user', 'reviews', 'availabilities']);
        $activeRelation = null;

        if ($viewer?->role === 'patient' && $viewer->patient) {
            $activeRelation = PatientDoctorRelationship::query()
                ->where('patient_id', $viewer->patient->id)
                ->where('doctor_id', $doctor->id)
                ->latest()
                ->value('status');
            if ($activeRelation === 'accepted') {
                $activeRelation = 'active';
            }
        }

        return [
            'id' => $doctor->id,
            'user_id' => $doctor->user_id,
            'user' => $doctor->user,
            'name' => $doctor->user?->name,
            'specialty' => $doctor->specialty,
            'fee' => $doctor->fee,
            'years_of_experience' => $doctor->years_of_experience,
            'rating' => round((float) $doctor->reviews->avg('rating'), 1),
            'about' => $doctor->about ?: $doctor->bio,
            'bio' => $doctor->bio,
            'availability' => $doctor->availabilities,
            'reviews' => $full ? $doctor->reviews : [],
            'follow_status' => $activeRelation,
            'available_days' => $doctor->available_days,
            'contact_info' => $doctor->contact_info,
            'license_number' => $full ? $doctor->license_number : null,
            'hospitals' => $full ? $doctor->hospitals : [],
        ];
    }
}
