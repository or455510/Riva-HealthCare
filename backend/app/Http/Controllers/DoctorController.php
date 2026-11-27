<?php

namespace App\Http\Controllers;

use App\Models\ClinicService;
use App\Models\ClinicUser;
use App\Models\Doctor;
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
    ) {}

    /**
     * COST (RIVA-107 / audit §22 P1-4): transformDoctor() used to run its
     * own fresh PatientDoctorRelationship query per doctor whenever the
     * viewer was a patient — a per-doctor query on this public, unpaginated
     * listing endpoint, i.e. an N+1 that scales with the total number of
     * active doctors on every single page load. All of a viewing patient's
     * relationships for the doctors on this page are now batch-loaded once
     * up front and passed into transformDoctor() as a precomputed lookup,
     * so the endpoint costs a fixed, small number of queries regardless of
     * how many doctors are listed.
     */
    /**
     * RIVA-141: real specialty + location filtering. `specialty` matches the
     * doctor's own `specialty` column (exact real field, no fabricated
     * taxonomy). `location` is a plain substring match against the doctor's
     * User.address — there is no lat/lng anywhere on User/Doctor in this
     * codebase (only PharmacyBranch has real coordinates, per RIVA-110), so
     * this is honestly a text filter, not a distance/"near me" computation.
     * A true "nearest doctor" feature needs real geocoding first — tracked
     * separately, not faked here.
     */
    public function index(Request $request)
    {
        $doctors = Doctor::with(['user', 'reviews', 'availabilities'])
            ->where('is_active', true)
            ->when($request->filled('specialty'), function ($query) use ($request) {
                $query->where('specialty', 'like', '%' . $request->string('specialty') . '%');
            })
            ->when($request->filled('location'), function ($query) use ($request) {
                $query->whereHas('user', function ($userQuery) use ($request) {
                    $userQuery->where('address', 'like', '%' . $request->string('location') . '%');
                });
            })
            ->get();

        $viewer = $request->user();
        $relationshipsByDoctorId = $this->loadViewerRelationships($viewer, $doctors->pluck('id'));

        $transformed = $doctors->map(
            fn (Doctor $doctor) => $this->transformDoctor(
                $doctor,
                $viewer,
                false,
                $relationshipsByDoctorId[$doctor->id] ?? null
            )
        );

        return $this->success($transformed);
    }

    /**
     * RIVA-141 follow-up (product-owner request, recorded 2026-08-25): "the
     * filtering is just text boxes, there's no dropdown suggesting options
     * to pick from." Returns the REAL, distinct specialty and address values
     * that already exist among active doctors — no fabricated taxonomy list,
     * only values a doctor actually entered — so the frontend can offer them
     * as pick-from-a-list suggestions (via <datalist>) instead of a blind
     * text field, while still allowing free typing for anything not yet
     * covered by an existing doctor's data.
     */
    public function filterOptions()
    {
        $specialties = Doctor::query()
            ->where('is_active', true)
            ->whereNotNull('specialty')
            ->where('specialty', '!=', '')
            ->distinct()
            ->orderBy('specialty')
            ->pluck('specialty')
            ->values();

        $locations = Doctor::query()
            ->where('is_active', true)
            ->whereHas('user', function ($query) {
                $query->whereNotNull('address')->where('address', '!=', '');
            })
            ->with('user:id,address')
            ->get()
            ->pluck('user.address')
            ->filter()
            ->unique()
            ->sort()
            ->values();

        return $this->success(['specialties' => $specialties, 'locations' => $locations]);
    }

    /**
     * Batch-loads the viewing patient's most-recent relationship status per
     * doctor in $doctorIds, in a single query, matching the exact
     * "most recent by created_at" semantics the old per-doctor
     * ->latest()->value('status') call used. Returns [] for a non-patient
     * viewer (guest, doctor, caregiver, admin) — transformDoctor() already
     * only looks at this for patient viewers, so an empty map is safe and
     * costs zero extra queries in that case.
     *
     * @return array<int, string> doctor_id => relationship status
     */
    private function loadViewerRelationships($viewer, $doctorIds): array
    {
        if (! ($viewer?->role === 'patient' && $viewer->patient) || $doctorIds->isEmpty()) {
            return [];
        }

        return PatientDoctorRelationship::query()
            ->where('patient_id', $viewer->patient->id)
            ->whereIn('doctor_id', $doctorIds)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get(['doctor_id', 'status'])
            ->unique('doctor_id') // first row per doctor_id wins = most recent, since ordered desc above
            ->pluck('status', 'doctor_id')
            ->all();
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

        if (! $hasActiveRelationship) {
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

    /**
     * Phase C (Services & Prices, principal-engineering directive): public,
     * read-only, so a patient booking this doctor can pick a specific priced
     * service instead of just paying the flat Doctor::fee — the exact same
     * visibility level as 'fee' itself in transformDoctor() above. Only
     * active services from clinics where this doctor has an active
     * membership are returned; a doctor with no clinic (shouldn't happen —
     * every doctor is auto-provisioned a solo clinic — but handled anyway)
     * simply gets an empty list, and AppointmentController::store() still
     * falls back to Doctor::fee when no service_id is sent.
     */
    public function services(Doctor $doctor)
    {
        $clinicIds = ClinicUser::where('user_id', $doctor->user_id)
            ->where('status', 'active')
            ->pluck('clinic_id');

        $services = ClinicService::whereIn('clinic_id', $clinicIds)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'clinic_id', 'name', 'description', 'price', 'duration_minutes']);

        return $this->success($services);
    }

    /**
     * $precomputedRelation: pass `false` (the default) to have this method
     * run its own single-doctor relationship lookup, as before — correct
     * and cheap for single-doctor callers like show(). index() instead
     * batch-loads every viewer/doctor relationship on the page up front and
     * passes the already-resolved status (a string) or `null` (no
     * relationship at all) explicitly, so this method must not run its own
     * query in that case even though the result is "no relation found."
     */
    private function transformDoctor(Doctor $doctor, $viewer = null, bool $full = false, string|false|null $precomputedRelation = false): array
    {
        $doctor->loadMissing(['user', 'reviews', 'availabilities']);
        $activeRelation = null;

        if ($viewer?->role === 'patient' && $viewer->patient) {
            $activeRelation = $precomputedRelation === false
                ? PatientDoctorRelationship::query()
                    ->where('patient_id', $viewer->patient->id)
                    ->where('doctor_id', $doctor->id)
                    ->latest()
                    ->value('status')
                : $precomputedRelation;

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
            'is_verified' => (bool) ($doctor->is_verified && $doctor->user?->doctor_verified_at),
            'doctor_verified_at' => $doctor->user?->doctor_verified_at,
            'verification_status' => $doctor->user?->verification_status ?? $doctor->verification_status,
        ];
    }
}
