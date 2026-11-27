<?php

namespace App\Http\Controllers;

use App\Models\Caregiver;
use App\Models\CaregiverReview;
use App\Models\PatientCaregiverRelationship;
use Illuminate\Http\Request;

class CaregiverController extends Controller
{
    /**
     * RIVA-141: real specialty + location filtering, mirroring
     * DoctorController::index(). `location` matches User.address as a plain
     * substring — no lat/lng exists on User/Caregiver, so this is a text
     * filter, not a real "near me" distance computation (that needs real
     * geocoding, tracked separately rather than faked here).
     */
    public function index(Request $request)
    {
        $caregivers = Caregiver::with(['user', 'reviews'])
            ->where('is_active', true)
            ->when($request->filled('specialty'), function ($query) use ($request) {
                $query->where('specialty', 'like', '%' . $request->string('specialty') . '%');
            })
            ->when($request->filled('location'), function ($query) use ($request) {
                $query->whereHas('user', function ($userQuery) use ($request) {
                    $userQuery->where('address', 'like', '%' . $request->string('location') . '%');
                });
            })
            ->get()
            ->map(fn (Caregiver $c) => $this->transform($c, $request->user()));

        return $this->success($caregivers);
    }

    /**
     * RIVA-141 follow-up (product-owner request, recorded 2026-08-25):
     * mirrors DoctorController::filterOptions() — real, distinct specialty
     * and address values already entered by active caregivers, so the
     * frontend can offer a real pick-from-a-list dropdown instead of a
     * blind text box. Free typing still works for anything not yet in the
     * list.
     */
    public function filterOptions()
    {
        $specialties = Caregiver::query()
            ->where('is_active', true)
            ->whereNotNull('specialty')
            ->where('specialty', '!=', '')
            ->distinct()
            ->orderBy('specialty')
            ->pluck('specialty')
            ->values();

        $locations = Caregiver::query()
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

    public function show(Request $request, Caregiver $caregiver)
    {
        $caregiver->load(['user', 'reviews.patient.user']);

        return $this->success($this->transform($caregiver, $request->user(), true));
    }

    public function reviews(Caregiver $caregiver)
    {
        $reviews = $caregiver->reviews()->with('patient.user')->latest()->get()
            ->map(fn (CaregiverReview $r) => [
                'id' => $r->id,
                'rating' => $r->rating,
                'comment' => $r->comment,
                'patient_name' => $r->patient?->user?->name ?? 'Patient',
                'created_at' => $r->created_at,
            ]);

        return $this->success($reviews);
    }

    public function storeReview(Request $request, Caregiver $caregiver)
    {
        $user = $request->user();
        abort_unless($user->role === 'patient' && $user->patient, 403);

        $hasRelationship = PatientCaregiverRelationship::query()
            ->where('patient_id', $user->patient->id)
            ->where('caregiver_id', $caregiver->id)
            ->whereIn('status', ['active', 'accepted'])
            ->exists();

        if (! $hasRelationship) {
            return $this->error('You can review this caregiver after your follow request is accepted.', 403);
        }

        $data = $request->validate([
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'comment' => ['nullable', 'string'],
        ]);

        $review = CaregiverReview::create([
            'caregiver_id' => $caregiver->id,
            'patient_id' => $user->patient->id,
            'rating' => $data['rating'],
            'comment' => $data['comment'] ?? '',
        ]);

        return $this->success($review, 'Review submitted successfully', 201);
    }

    private function transform(Caregiver $c, $viewer = null, bool $full = false): array
    {
        $activeRelation = null;
        if ($viewer?->role === 'patient' && $viewer->patient) {
            $activeRelation = PatientCaregiverRelationship::query()
                ->where('patient_id', $viewer->patient->id)
                ->where('caregiver_id', $c->id)
                ->latest()
                ->value('status');
            if ($activeRelation === 'accepted') {
                $activeRelation = 'active';
            }
        }

        return [
            'id' => $c->id,
            'user_id' => $c->user_id,
            'name' => $c->user?->name,
            'user' => $c->user,
            'specialty' => $c->specialty,
            'experience_years' => $c->experience_years,
            'salary' => $c->salary,
            'about' => $c->about,
            'rating' => round((float) $c->reviews->avg('rating'), 1),
            'reviews_count' => $c->reviews->count(),
            'follow_status' => $activeRelation,
            'reviews' => $full ? $c->reviews : [],
        ];
    }
}
