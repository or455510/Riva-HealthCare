<?php

namespace App\Http\Controllers;

use App\Models\DailyStatus;
use App\Models\Patient;
use App\Models\PatientCaregiverRelationship;
use App\Models\PatientDoctorRelationship;
use App\Services\NotificationService;
use App\Services\RiskAssessmentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class DailyStatusController extends Controller
{
    public function __construct(
        private readonly RiskAssessmentService $riskAssessmentService,
        private readonly NotificationService $notificationService
    ) {
    }

    public function index(Request $request)
    {
        $patient = $request->user()->patient;
        abort_unless($patient, 403);

        return $this->success($patient->dailyStatuses()->latest()->get());
    }

    public function store(Request $request)
    {
        $startedAt = microtime(true);
        $patient = $request->user()->patient;
        abort_unless($patient, 403);

        $data = $request->validate([
            'mood' => ['nullable', 'string', 'max:255'],
            'date' => ['nullable', 'date'],
            'pain_level' => ['nullable', 'integer', 'min:0', 'max:10'],
            'sleep_hours' => ['nullable', 'integer', 'min:0', 'max:24'],
            'sleep_quality' => ['nullable', 'string', 'max:255'],
            'weight' => ['nullable', 'numeric', 'min:0', 'max:300'],
            'blood_pressure_systolic' => ['nullable', 'integer', 'min:60', 'max:240'],
            'blood_pressure_diastolic' => ['nullable', 'integer', 'min:30', 'max:160'],
            'temperature' => ['nullable', 'numeric', 'min:30', 'max:45'],
            'heart_rate' => ['nullable', 'integer', 'min:20', 'max:240'],
            'symptoms' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
            'medication_taken' => ['nullable'],
            'additional_data' => ['nullable', 'array'],
        ]);

        $data['date'] = $data['date'] ?? now()->toDateString();
        $data['pain_level'] = $data['pain_level'] ?? 0;
        $data['risk_level'] = $this->riskAssessmentService->classify($data, $patient);
        $data['medication_taken'] = $this->normalizeMedicationTaken($data['medication_taken'] ?? null);
        $status = DailyStatus::updateOrCreate(
            ['patient_id' => $patient->id, 'date' => $data['date']],
            $data
        );

        $notificationsDeferred = false;

        $dispatchNotifications = function () use ($patient, $status, $startedAt): void {
            $notificationStartedAt = microtime(true);

            try {
                $created = $this->dispatchRiskNotifications($patient, $status);

                Log::info('Daily status risk notifications processed', [
                    'patient_id' => $patient->id,
                    'daily_status_id' => $status->id,
                    'risk_level' => $status->risk_level,
                    'notifications_created' => count($created),
                    'notification_duration_ms' => (int) round((microtime(true) - $notificationStartedAt) * 1000),
                    'total_elapsed_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                ]);
            } catch (Throwable $exception) {
                Log::warning('Daily status risk notifications failed after status was saved', [
                    'patient_id' => $patient->id,
                    'daily_status_id' => $status->id,
                    'risk_level' => $status->risk_level,
                    'exception' => $exception::class,
                    'message' => $exception->getMessage(),
                ]);
            }
        };

        if (function_exists('defer')) {
            defer($dispatchNotifications);
            $notificationsDeferred = true;
        } else {
            $dispatchNotifications();
        }

        Log::info('Daily status saved', [
            'patient_id' => $patient->id,
            'daily_status_id' => $status->id,
            'risk_level' => $status->risk_level,
            'notifications_deferred' => $notificationsDeferred,
            'save_response_duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);

        return $this->success([
            'status' => $status,
            'risk_level' => $status->risk_level,
            'notifications_deferred' => $notificationsDeferred,
        ], 'Daily status saved successfully', 201);
    }

    public function latest(Request $request)
    {
        $patient = $request->user()->patient;
        abort_unless($patient, 403);

        return $this->success($patient->dailyStatuses()->latest()->first());
    }

    public function patientStatuses(Request $request, Patient $patient)
    {
        $user = $request->user();
        if ($user->role === 'doctor') {
            abort_unless(PatientDoctorRelationship::where('doctor_id', $user->doctor?->id)
                ->where('patient_id', $patient->id)
                ->whereIn('status', ['pending', 'active', 'accepted'])
                ->exists(), 403);
        } elseif ($user->role === 'caregiver') {
            abort_unless(PatientCaregiverRelationship::where('caregiver_id', $user->caregiver?->id)
                ->where('patient_id', $patient->id)
                ->whereIn('status', ['pending', 'active', 'accepted'])
                ->exists(), 403);
        } else {
            abort_unless($user->role === 'admin', 403);
        }

        return $this->success($patient->dailyStatuses()->latest()->get());
    }

    private function dispatchRiskNotifications(Patient $patient, DailyStatus $status): array
    {
        $created = [];

        if ($status->risk_level === 'stable') {
            return $created;
        }

        $caregiverRelationships = PatientCaregiverRelationship::with('caregiver.user')
            ->where('patient_id', $patient->id)
            ->whereIn('status', ['active', 'accepted'])
            ->get();

        foreach ($caregiverRelationships as $relationship) {
            if ($relationship->caregiver?->user) {
                $patientName = $patient->user?->name ?? 'A patient';
                $body = "{$patientName}'s daily check-in requires attention. Risk level: {$status->risk_level}. Pain: {$status->pain_level}/10. Symptoms: " . ($status->symptoms ?: 'none reported') . ".";
                $created[] = $this->notificationService->create(
                    $relationship->caregiver->user,
                    'daily_status_risk',
                        'Patient needs attention',
                        $body,
                        [
                            'patient_id' => $patient->id,
                            'patient_name' => $patientName,
                            'risk_level' => $status->risk_level,
                            'pain_level' => $status->pain_level,
                            'symptoms' => $status->symptoms,
                            'daily_status_id' => $status->id,
                            'source' => 'daily_status',
                            'recommendation' => 'Review the latest check-in and contact the patient if needed.',
                        ],
                        '/report/' . $patient->id,
                        'emergency'
                    );
            }
        }

        if (in_array($status->risk_level, ['moderate_risk', 'high_risk'], true)) {
            $doctorRelationships = PatientDoctorRelationship::with('doctor.user')
                ->where('patient_id', $patient->id)
                ->whereIn('status', ['active', 'accepted'])
                ->get();

            foreach ($doctorRelationships as $relationship) {
                if ($relationship->doctor?->user) {
                    $patientName = $patient->user?->name ?? 'A patient';
                    $body = "{$patientName}'s daily check-in requires medical review. Risk level: {$status->risk_level}. Pain: {$status->pain_level}/10. Symptoms: " . ($status->symptoms ?: 'none reported') . ".";
                    $created[] = $this->notificationService->create(
                        $relationship->doctor->user,
                        'daily_status_risk',
                        'Patient risk alert',
                        $body,
                        [
                            'patient_id' => $patient->id,
                            'patient_name' => $patientName,
                            'risk_level' => $status->risk_level,
                            'pain_level' => $status->pain_level,
                            'symptoms' => $status->symptoms,
                            'daily_status_id' => $status->id,
                            'source' => 'daily_status',
                            'recommendation' => 'Open the patient report and review the latest check-in.',
                        ],
                        '/report/' . $patient->id,
                        'emergency'
                    );
                }
            }
        }

        return $created;
    }

    private function normalizeMedicationTaken(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 'yes', 'taken'], true);
    }
}
