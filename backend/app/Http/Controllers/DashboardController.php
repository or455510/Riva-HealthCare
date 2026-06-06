<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\DailyStatus;
use App\Models\Diagnosis;
use App\Models\Doctor;
use App\Models\LoginAttempt;
use App\Models\MedicationLog;
use App\Models\Notification;
use App\Models\PatientDoctorRelationship;
use App\Models\Report;
use App\Models\SystemActivityLog;
use App\Models\User;
use App\Services\MedicationAdherenceService;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __construct(private readonly MedicationAdherenceService $adherenceService)
    {
    }

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

        return $this->success([
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
            'recent_daily_statuses' => $statuses->take(10)->values(),
            'risk_trends' => [
                'stable' => $stable,
                'attention_needed' => $attention,
                'high_risk' => $critical,
            ],
            'patients' => $relationships,
        ]);
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
            'adherence_overview' => MedicationLog::whereIn('patient_id', $patientIds)->selectRaw("status, count(*) as total")->groupBy('status')->get(),
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
