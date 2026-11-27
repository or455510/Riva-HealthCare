<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\DailyStatus;
use App\Models\Doctor;
use App\Models\LoginAttempt;
use App\Models\MedicationLog;
use App\Models\AppointmentBookingLog;
use App\Models\EmergencyAlert;
use App\Models\Notification;
use App\Models\Patient;
use App\Models\PatientCaregiverRelationship;
use App\Models\PatientDoctorRelationship;
use App\Models\Payment;
use App\Models\Report;
use App\Models\SystemActivityLog;
use App\Models\User;
use App\Services\MedicationAdherenceService;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __construct(private readonly MedicationAdherenceService $adherenceService) {}

    public function patient(Request $request)
    {
        $patient = $request->user()->patient;
        abort_unless($patient, 403);

        $activeDoctor = PatientDoctorRelationship::with('doctor.user')
            ->where('patient_id', $patient->id)
            ->where('status', 'active')
            ->latest()
            ->first();

        $activeCaregiver = $patient->caregiverRelationships()->with('caregiver.user')->where('status', 'active')->latest()->first();
        $notifications = $request->user()->notifications()->latest()->take(10)->get();
        $todaySchedule = $this->adherenceService->todaySchedule($patient);
        $medicationLogs = MedicationLog::where('patient_id', $patient->id)->get();
        $taken = $medicationLogs->where('status', 'taken')->count();
        $totalLogs = max($medicationLogs->count(), 1);

        return $this->success([
            'latest_daily_status' => $patient->dailyStatuses()->latest()->first(),
            'risk_level' => $patient->dailyStatuses()->latest()->value('risk_level') ?? 'stable',
            'medications_today' => $todaySchedule['doses'],
            'today_medication_schedule' => $todaySchedule,
            'adherence_percentage' => $todaySchedule['summary']['adherence_percentage'] ?? round(($taken / $totalLogs) * 100, 2),
            'notifications' => $notifications,
            'active_doctor' => $activeDoctor,
            'active_caregiver' => $activeCaregiver,
            'appointments' => Appointment::where('patient_id', $patient->id)->latest()->take(5)->get(),
            'daily_tasks' => [
                ['label' => 'Submit daily status', 'done' => (bool) $patient->dailyStatuses()->whereDate('created_at', today())->exists()],
                ['label' => 'Review medications', 'done' => false],
            ],
        ]);
    }

    /**
     * RIVA-137: profile-p (the patient-record page) used to have no
     * dedicated single-patient endpoint at all — it fetched the WHOLE
     * doctor()/caregiver() dashboard payload above and searched the
     * returned patient list client-side for a matching id. That silently
     * 403'd for an admin viewer, because it always called the doctor-only
     * dashboard()->doctor endpoint regardless of the viewer's real role
     * (admin has no Doctor row, so `abort_unless($doctor, 403)` fired).
     * This endpoint is role-correct for all three viewer roles and doesn't
     * require pulling a whole dashboard's worth of data to render one
     * patient record. Mirrors the same relationship-gating pattern already
     * used and tested in DailyStatusController::patientStatuses() (doctor/
     * caregiver need an active or accepted relationship; admin always
     * passes) — deliberately NOT 'pending', since a pending relationship
     * should stay a preview elsewhere (see ReportController's access-mode
     * pattern), not the full patient record.
     */
    public function patientProfile(Request $request, Patient $patient)
    {
        $user = $request->user();

        if ($user->role === 'doctor') {
            abort_unless(PatientDoctorRelationship::where('doctor_id', $user->doctor?->id)
                ->where('patient_id', $patient->id)
                ->whereIn('status', ['active', 'accepted'])
                ->exists(), 403);
        } elseif ($user->role === 'caregiver') {
            abort_unless(PatientCaregiverRelationship::where('caregiver_id', $user->caregiver?->id)
                ->where('patient_id', $patient->id)
                ->whereIn('status', ['active', 'accepted'])
                ->exists(), 403);
        } else {
            abort_unless($user->role === 'admin', 403);
        }

        $patient->loadMissing('user');

        return $this->success(['patient' => $patient]);
    }

    public function doctor(Request $request)
    {
        $doctor = $request->user()->doctor;
        abort_unless($doctor, 403);

        $relationships = PatientDoctorRelationship::with('patient.user')
            ->where('doctor_id', $doctor->id)
            ->get();

        $activePatientIds = $relationships->whereIn('status', ['active', 'accepted'])->pluck('patient_id');
        $statuses = DailyStatus::whereIn('patient_id', $activePatientIds)->latest()->take(50)->get();
        $latestStatuses = $statuses->unique('patient_id');
        $medicationLogs = MedicationLog::whereIn('patient_id', $activePatientIds)->get();
        $taken = $medicationLogs->where('status', 'taken')->count();
        $total = $medicationLogs->count();
        $stable = $latestStatuses->where('risk_level', 'stable')->count();
        $attention = $latestStatuses->whereIn('risk_level', ['attention_needed', 'moderate_risk'])->count();
        $critical = $latestStatuses->where('risk_level', 'high_risk')->count();
        $statusDistribution = [
            'stable' => $stable,
            'attention' => $attention,
            'critical' => $critical,
        ];

        return $this->success(array_merge([
            'total_patients' => $relationships->whereIn('status', ['active', 'accepted'])->count(),
            'total_assigned_patients' => $relationships->whereIn('status', ['active', 'accepted'])->count(),
            'stable_patients' => $stable,
            'attention_patients' => $attention,
            'critical_patients' => $critical,
            'high_risk_patients' => $critical,
            'pending_follow_requests' => $relationships->where('status', 'pending')->count(),
            'reports_count' => Report::where('doctor_id', $doctor->id)->count(),
            'average_adherence' => $total > 0 ? round(($taken / $total) * 100, 2) : 0,
            'missed_medications' => $medicationLogs->where('status', 'missed')->count(),
            'weekly_reports' => DailyStatus::whereIn('patient_id', $activePatientIds)->where('created_at', '>=', now()->subDays(7))->count(),
            'weekly_patients' => $this->weeklyPatientActivity($activePatientIds),
            'patient_status_distribution' => $statusDistribution,
            'appointments' => Appointment::where('doctor_id', $doctor->id)->latest()->take(5)->get(),
            'recent_daily_statuses' => $this->attentionOrderedStatuses($latestStatuses),
            'risk_trends' => [
                'stable' => $stable,
                'attention_needed' => $attention,
                'high_risk' => $critical,
            ],
            'patients' => $relationships,
            'active_emergency_alerts' => $this->emergencyAlertsSummary($activePatientIds),
            'recent_bookings' => $this->recentBookings($doctor->id),
        ], $this->clinicAnalytics($doctor->id)));
    }

    /**
     * RIVA-150 (Doctor ERP Dashboard spec §10.5): `recent_daily_statuses`
     * used to be the raw latest-50 query's first 10 rows — NOT deduplicated
     * per patient, so one chatty-but-stable patient's routine check-ins
     * could push a different patient's actually-concerning submission off
     * the list entirely. `$latestStatuses` (built by the caller via
     * `->unique('patient_id')` on a `->latest()`-ordered query) already
     * correctly holds each patient's single MOST RECENT status — this just
     * re-sorts that same in-memory collection so non-stable ones surface
     * first, without a second database query and without changing what
     * `$stable`/`$attention`/`$critical` (computed from the same collection
     * before this call) mean.
     */
    private function attentionOrderedStatuses($latestStatuses)
    {
        $priority = ['high_risk' => 0, 'moderate_risk' => 1, 'attention_needed' => 2, 'stable' => 3];

        return $latestStatuses
            ->sortBy(fn ($status) => $priority[$status->risk_level] ?? 4)
            ->take(10)
            ->values();
    }

    /**
     * RIVA-150 (Doctor ERP Dashboard spec §10.6): the dashboard previously
     * never queried `EmergencyAlert` at all. Scoped by the same
     * relationship-based boundary as every other medical-data field on this
     * endpoint (`$activePatientIds` — active/accepted `PatientDoctorRelationship`
     * rows only), never by clinic membership, per this codebase's own
     * established rule that clinic co-membership is insufficient
     * authorization for medical data (see CrossClinicResourceIsolationTest).
     */
    private function emergencyAlertsSummary($patientIds): array
    {
        $ids = collect($patientIds)->filter()->values();

        if ($ids->isEmpty()) {
            return ['count' => 0, 'items' => []];
        }

        $activeAlerts = EmergencyAlert::whereIn('patient_id', $ids)
            ->where('status', 'active')
            ->with('patient.user');

        return [
            'count' => (clone $activeAlerts)->count(),
            'items' => (clone $activeAlerts)->latest()->take(5)->get()->map(fn (EmergencyAlert $alert) => [
                'id' => $alert->id,
                'patient_id' => $alert->patient_id,
                'patient_name' => $alert->patient?->user?->name,
                'severity' => $alert->severity,
                'message' => $alert->message,
                'pain_level' => $alert->pain_level,
                'symptoms' => $alert->symptoms,
                'created_at' => $alert->created_at,
            ])->values(),
        ];
    }

    /**
     * RIVA-150 (Doctor ERP Dashboard spec §10.9): `AppointmentBookingLog` is
     * the only real activity-log table available today, and it is written
     * to in exactly one place (`AppointmentController::store()`, action
     * 'created') — this deliberately does NOT claim to be a full activity
     * feed (no confirm/cancel/pay/queue-transition events exist in it), and
     * the frontend must label it "Recent bookings," never "Recent activity,"
     * to match what this data actually is.
     */
    private function recentBookings(int $doctorId): array
    {
        return AppointmentBookingLog::whereHas('appointment', fn ($q) => $q->where('doctor_id', $doctorId))
            ->with(['appointment.patient.user', 'user'])
            ->latest()
            ->take(5)
            ->get()
            ->map(fn (AppointmentBookingLog $log) => [
                'id' => $log->id,
                'appointment_id' => $log->appointment_id,
                'patient_name' => $log->appointment?->patient?->user?->name,
                'action' => $log->action,
                'notes' => $log->notes,
                'actor_name' => $log->user?->name,
                'created_at' => $log->created_at,
            ])->values()->all();
    }

    /**
     * RIVA-150 (product-owner request, 2026-08-25, page-by-page dashboard
     * rebuild — Doctor Dashboard homepage): "where are the boxes with the
     * clinic's analytics — case count, consultation count, checkups, daily
     * checkups, revenue/profit — the things every real EMR/ERP home page
     * shows?" Grounded in real vendor/analytics-vendor research (OmniMD's
     * physician-dashboard operational + financial KPI split, and Knowi's
     * "visit volume / revenue per visit / no-show rate" clinic-operations
     * metrics) rather than invented from memory, and scaled down to what
     * this system actually has real data for: no insurance claims or payer
     * mix here, so this intentionally omits AR-aging/denial-rate metrics
     * that don't apply to a cash/wallet-pay Egyptian clinic — see
     * DoctorPaymentMethodTest/PaymentMethodController for that model.
     *
     * Revenue is computed from the `payments` table (one row per charge
     * ATTEMPT — see its migration docblock), never from the legacy
     * `appointments.payment_status` single column, because only `payments`
     * distinguishes a truly collected amount (status=succeeded, minus any
     * refund) from a merely-attempted or since-refunded one.
     */
    private function clinicAnalytics(int $doctorId): array
    {
        $today = now()->toDateString();
        $monthStart = now()->startOfMonth();

        $appointmentsBase = fn () => Appointment::where('doctor_id', $doctorId);

        $todayAppointments = $appointmentsBase()->where('appointment_date', $today)->get();
        $todayCancelled = $todayAppointments->where('status', 'cancelled')->count();
        $totalConsultations = (clone $appointmentsBase())->where('status', 'completed')->count();
        $monthlyConsultations = (clone $appointmentsBase())->where('status', 'completed')
            ->where('appointment_date', '>=', $monthStart->toDateString())
            ->count();
        $cancelledThisMonth = (clone $appointmentsBase())->where('status', 'cancelled')
            ->where('appointment_date', '>=', $monthStart->toDateString())
            ->count();

        $paidPayments = fn () => Payment::whereHas('appointment', fn ($q) => $q->where('doctor_id', $doctorId))
            ->where('status', 'succeeded');

        $revenueTotal = (float) (clone $paidPayments())->selectRaw('COALESCE(SUM(amount - refunded_amount), 0) as total')->value('total');
        $revenueThisMonth = (float) (clone $paidPayments())
            ->where('created_at', '>=', $monthStart)
            ->selectRaw('COALESCE(SUM(amount - refunded_amount), 0) as total')->value('total');
        $revenueToday = (float) (clone $paidPayments())
            ->whereDate('created_at', $today)
            ->selectRaw('COALESCE(SUM(amount - refunded_amount), 0) as total')->value('total');
        $pendingPayments = Payment::whereHas('appointment', fn ($q) => $q->where('doctor_id', $doctorId))
            ->where('status', 'pending')->count();

        return [
            'today_appointments_count' => $todayAppointments->count(),
            'today_completed_checkups' => $todayAppointments->where('status', 'completed')->count(),
            'today_pending_appointments' => $todayAppointments->whereIn('status', ['pending', 'confirmed', 'pending_payment'])->count(),
            'today_cancelled_count' => $todayCancelled,
            'total_consultations' => $totalConsultations,
            'monthly_consultations' => $monthlyConsultations,
            'cancelled_this_month' => $cancelledThisMonth,
            'revenue_total' => round($revenueTotal, 2),
            'revenue_this_month' => round($revenueThisMonth, 2),
            'revenue_today' => round($revenueToday, 2),
            'average_revenue_per_visit' => $totalConsultations > 0 ? round($revenueTotal / $totalConsultations, 2) : 0,
            'pending_payments_count' => $pendingPayments,
            'currency' => 'EGP',
            'weekly_revenue' => $this->weeklyRevenue($doctorId),
        ];
    }

    private function weeklyRevenue(int $doctorId): array
    {
        $weekStart = now()->startOfWeek();

        return collect(range(0, 6))->map(function (int $offset) use ($doctorId, $weekStart) {
            $date = $weekStart->copy()->addDays($offset);

            return (float) Payment::whereHas('appointment', fn ($q) => $q->where('doctor_id', $doctorId))
                ->where('status', 'succeeded')
                ->whereDate('created_at', $date)
                ->selectRaw('COALESCE(SUM(amount - refunded_amount), 0) as total')
                ->value('total');
        })->all();
    }

    public function caregiver(Request $request)
    {
        $caregiver = $request->user()->caregiver;
        abort_unless($caregiver, 403);

        $relationships = $caregiver->patientRelationships()->with('patient.user')->whereIn('status', ['active', 'accepted'])->get();
        $patientIds = $relationships->pluck('patient_id');
        $todayMissed = MedicationLog::whereIn('patient_id', $patientIds)->whereDate('created_at', today())->where('status', 'missed')->count();
        $statuses = DailyStatus::whereIn('patient_id', $patientIds)->latest()->take(50)->get();
        $latestStatuses = $statuses->unique('patient_id');
        $attention = $latestStatuses->whereIn('risk_level', ['attention_needed', 'moderate_risk'])->count();
        $critical = $latestStatuses->where('risk_level', 'high_risk')->count();
        $logs = MedicationLog::whereIn('patient_id', $patientIds)->get();
        $taken = $logs->where('status', 'taken')->count();
        $total = $logs->count();
        $stable = $latestStatuses->where('risk_level', 'stable')->count();
        $statusDistribution = [
            'stable' => $stable,
            'attention' => $attention,
            'critical' => $critical,
        ];

        return $this->success([
            'assigned_patients' => $relationships,
            'assigned_patients_count' => $relationships->count(),
            'total_patients' => $relationships->count(),
            'stable_patients' => $stable,
            'attention_patients' => $attention,
            'critical_patients' => $critical,
            'missed_medications_today' => $todayMissed,
            'missed_medications' => $logs->where('status', 'missed')->count(),
            'attention_needed_cases' => $attention + $critical,
            'average_adherence' => $total > 0 ? round(($taken / $total) * 100, 2) : 0,
            'weekly_reports' => DailyStatus::whereIn('patient_id', $patientIds)->where('created_at', '>=', now()->subDays(7))->count(),
            'weekly_patients' => $this->weeklyPatientActivity($patientIds),
            'patient_status_distribution' => $statusDistribution,
            'support_alerts' => $request->user()->notifications()->latest()->take(10)->get(),
            'daily_tasks' => [
                ['label' => 'Review missed medications', 'done' => false],
                ['label' => 'Check patient alerts', 'done' => false],
            ],
            'adherence_overview' => MedicationLog::whereIn('patient_id', $patientIds)->selectRaw('status, count(*) as total')->groupBy('status')->get(),
            'patient_status_summary' => $statuses->take(10)->values(),
        ]);
    }

    private function weeklyPatientActivity($patientIds): array
    {
        $ids = collect($patientIds)->filter()->values();
        $weekStart = now()->startOfWeek();

        if ($ids->isEmpty()) {
            return array_fill(0, 7, 0);
        }

        return collect(range(0, 6))->map(function (int $offset) use ($ids, $weekStart) {
            $date = $weekStart->copy()->addDays($offset);

            return DailyStatus::whereIn('patient_id', $ids)
                ->whereDate('created_at', $date)
                ->distinct('patient_id')
                ->count('patient_id');
        })->all();
    }

    public function admin()
    {
        return $this->success([
            'total_users' => User::count(),
            'total_patients' => User::where('role', 'patient')->count(),
            'total_doctors' => User::where('role', 'doctor')->count(),
            'total_caregivers' => User::where('role', 'caregiver')->count(),
            'pending_doctor_verifications' => Doctor::where('verification_status', 'pending')->count(),
            'active_alerts' => Notification::whereNull('read_at')->count(),
            'high_risk_cases' => DailyStatus::where('risk_level', 'high_risk')->count(),
            'recent_registrations' => User::latest()->take(10)->get(),
            'suspicious_login_attempts' => LoginAttempt::where('successful', false)->latest()->take(10)->get(),
            'system_activity' => SystemActivityLog::latest('created_at')->take(20)->get(),
            'reports_overview' => Report::latest()->take(10)->get(),
            'appointments_overview' => Appointment::latest()->take(10)->get(),
        ]);
    }
}
