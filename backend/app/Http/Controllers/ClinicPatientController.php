<?php

namespace App\Http\Controllers;

use App\Models\Clinic;
use App\Models\ClinicUser;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\PatientDoctorRelationship;
use App\Models\User;
use App\Services\ActivityLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Phase C (Patient registration/search, principal-engineering directive):
 * the first real consumer of ClinicUser::PERMISSION_REGISTER_PATIENTS — the
 * last of the three originally-defined-but-unconsumed Slice 3 permissions
 * (queue.manage and payments.record were wired up in the Queue slice).
 * Deliberately scoped narrowly: this controller only ensures a real,
 * findable Patient record exists for a walk-in — it does not create
 * appointments or PatientDoctorRelationship rows (that remains
 * AppointmentController::store()'s job, which already creates the
 * relationship at booking time). Search is intentionally exact-match on
 * email or phone only, never a fuzzy/partial name search across the whole
 * patient table — a receptionist has the patient in front of them and asks
 * for a phone/email; a partial-name browse across every Riva patient would
 * be a real PII exposure this feature has no legitimate need for.
 */
class ClinicPatientController extends Controller
{
    public function __construct(private readonly ActivityLogService $activityLogService) {}

    public function search(Request $request)
    {
        $this->currentClinic($request);
        $this->authorizeRegister($request);

        $data = $request->validate([
            'email' => ['nullable', 'email'],
            'phone' => ['nullable', 'string'],
        ]);

        if (empty($data['email']) && empty($data['phone'])) {
            return $this->error('Provide an email or phone number to search.', 422);
        }

        $query = User::query()->where('role', 'patient')->with('patient');

        if (! empty($data['email'])) {
            $query->where('email', $data['email']);
        } else {
            $query->where('phone', $data['phone']);
        }

        $user = $query->first();

        if (! $user || ! $user->patient) {
            return $this->success(['found' => false]);
        }

        return $this->success(['found' => true, 'patient' => $this->serializePatient($user)]);
    }

    /**
     * RIVA-141: real name search, added per the product owner's explicit
     * decision — but deliberately NOT a fuzzy browse across every Riva
     * patient (that stays the PII exposure the class docblock above warns
     * about). This is scoped to patients this clinic already has a real
     * relationship with, i.e. patients of a doctor who is an active member
     * of this clinic — the same population a receptionist here could
     * legitimately already know by name. A brand-new walk-in with no prior
     * relationship still has to go through search()/register() above.
     * Requires 3+ characters to keep results meaningful and cheap.
     *
     * Deliberately does NOT filter clinic_user rows by role='doctor': a
     * solo-practice clinic's doctor is its OWNER (ClinicUser.role='owner',
     * see ClinicProvisioningService's auto-provisioning), not a 'doctor'
     * row — filtering by role would silently exclude that doctor's own
     * patients. Instead every active member's user_id is intersected
     * against the real Doctor table, which naturally keeps only the
     * clinic's actual doctors (owner-doctor or invited doctor members)
     * and drops staff/admin members who have no Doctor row.
     */
    public function searchByName(Request $request)
    {
        $clinic = $this->currentClinic($request);
        $this->authorizeRegister($request);

        $data = $request->validate([
            'q' => ['required', 'string', 'min:3', 'max:100'],
        ]);

        $memberUserIds = ClinicUser::query()
            ->where('clinic_id', $clinic->id)
            ->where('status', 'active')
            ->pluck('user_id');

        $doctorIds = Doctor::query()->whereIn('user_id', $memberUserIds)->pluck('id');

        $patientIds = PatientDoctorRelationship::query()
            ->whereIn('doctor_id', $doctorIds)
            ->pluck('patient_id');

        $patientUserIds = Patient::query()->whereIn('id', $patientIds)->pluck('user_id');

        $users = User::query()
            ->where('role', 'patient')
            ->whereIn('id', $patientUserIds)
            ->where('name', 'like', '%' . $data['q'] . '%')
            ->with('patient')
            ->limit(20)
            ->get();

        return $this->success([
            'patients' => $users->map(fn (User $user) => $this->serializePatient($user))->values(),
        ]);
    }

    /**
     * Idempotency is deliberately NOT automatic here (unlike
     * ClinicProvisioningService's "skip if already exists" pattern) — a
     * duplicate email returns 409, forcing the front desk through search()
     * first rather than silently reusing an account a receptionist may not
     * have actually verified belongs to the person in front of them.
     */
    public function register(Request $request)
    {
        $clinic = $this->currentClinic($request);
        $this->authorizeRegister($request);

        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'gender' => ['nullable', 'string', 'max:50'],
            'age' => ['nullable', 'integer', 'min:0', 'max:120'],
            'blood_type' => ['nullable', 'string', 'max:20'],
            'emergency_contact' => ['nullable', 'string', 'max:255'],
        ]);

        if (User::where('email', $data['email'])->exists()) {
            return $this->error('A Riva account with this email already exists. Search for the existing patient instead.', 409);
        }

        $user = User::create([
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'name' => trim($data['first_name'].' '.$data['last_name']),
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'role' => 'patient',
            // No self-service registration happened here — the walk-in has
            // no password of their own yet. A random, never-surfaced value
            // is set so the account is unusable until the patient goes
            // through the existing forgot-password flow to claim it. This
            // is an honest, deliberately interim design (matching
            // ManualPaymentGateway's own precedent) — see this slice's
            // journal entry for what remains genuinely unclosed (no email
            // delivery is configured/verified in this environment).
            'password' => Str::random(40),
            'profile_completed_at' => null,
        ]);

        $patient = Patient::create([
            'user_id' => $user->id,
            'gender' => $data['gender'] ?? null,
            'age' => $data['age'] ?? null,
            'blood_type' => $data['blood_type'] ?? null,
            'emergency_contact' => $data['emergency_contact'] ?? null,
        ]);

        $this->activityLogService->log(
            $request->user(),
            'clinic.patient.registered',
            "Registered walk-in patient {$user->email} at clinic {$clinic->name}",
            $request
        );

        return $this->success(
            $this->serializePatient($user->fresh('patient')),
            'Patient registered. They can use "forgot password" to access their own account.',
            201
        );
    }

    private function currentClinic(Request $request): Clinic
    {
        $membership = $request->attributes->get('clinic_membership');
        abort_unless($membership, 403, 'No active clinic context.');

        return $membership->clinic;
    }

    private function authorizeRegister(Request $request): void
    {
        $membership = $request->attributes->get('clinic_membership');
        abort_unless(
            $membership->hasPermission(ClinicUser::PERMISSION_REGISTER_PATIENTS),
            403,
            'You do not have permission to register or search patients.'
        );
    }

    private function serializePatient(User $user): array
    {
        return [
            'user_id' => $user->id,
            'patient_id' => $user->patient?->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'gender' => $user->patient?->gender,
            'age' => $user->patient?->age,
            'profile_completed' => (bool) $user->profile_completed_at,
        ];
    }
}
