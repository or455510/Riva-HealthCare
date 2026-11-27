<?php

namespace App\Http\Controllers;

use App\Jobs\SendEmergencyAlertNotifications;
use App\Models\EmergencyAlert;
use App\Models\Notification;
use App\Models\PatientCaregiverRelationship;
use App\Models\PatientDoctorRelationship;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * RELIABILITY (Phase 0 / S1): this controller used to attempt email + WhatsApp
 * delivery synchronously, once, inline in the request, and always returned a
 * success response regardless of whether delivery actually worked. That gap is
 * closed by SendEmergencyAlertNotifications (app/Jobs) which retries with backoff
 * and escalates in-app to admins if every attempt is exhausted — see that class's
 * docblock for the full reasoning. This controller now only does the part that can
 * never fail silently: saving the alert record and creating in-app notifications
 * for the patient's own care team, both of which are synchronous DB writes.
 */
class EmergencyAlertController extends Controller
{
    public function store(Request $request)
    {
        $patient = $request->user()->patient;
        abort_unless($patient, 403);

        $data = $request->validate([
            'severity' => ['nullable', 'string', 'in:medium,high,critical'],
            'message' => ['nullable', 'string', 'max:1000'],
            'pain_level' => ['nullable', 'integer', 'min:0', 'max:10'],
            'symptoms' => ['nullable', 'string', 'max:5000'],
        ]);

        $alertMessage = $data['message'] ?? 'Emergency alert: Patient needs immediate care.';

        $alert = EmergencyAlert::create([
            'patient_id' => $patient->id,
            'severity' => $data['severity'] ?? 'critical',
            'message' => $alertMessage,
            'pain_level' => $data['pain_level'] ?? null,
            'symptoms' => $data['symptoms'] ?? null,
        ]);

        // SECURITY (RIVA-102): do not log clinical values (pain_level, symptoms) —
        // operational logs are readable by anyone with server/hosting access, who
        // is not necessarily authorized to see a patient's clinical data. The
        // in-app $payload below (delivered only to the patient's actual care team)
        // still carries pain_level/symptoms, which is correct and unaffected by
        // this change. See docs/RIVA_SECURITY.md and the cost/scalability audit
        // §22 P2-5.
        Log::info('Emergency alert saved', [
            'emergency_alert_id' => $alert->id,
            'patient_id' => $patient->id,
            'severity' => $alert->severity,
        ]);

        $patientName = $patient->user?->name ?: $patient->user?->full_name ?: 'A patient';
        $conditions = $patient->chronic_conditions ?: 'No chronic condition recorded';
        $body = "Emergency alert: {$patientName} reported {$alertMessage}. Condition: {$conditions}. Severity: {$alert->severity}. Submitted {$alert->created_at->format('g:i A')}.";

        $payload = [
            'patient_id' => $patient->id,
            'patient_name' => $patientName,
            'condition' => $conditions,
            'severity' => $alert->severity,
            'pain_level' => $alert->pain_level,
            'symptoms' => $alert->symptoms,
            'trigger_source' => 'emergency_button',
            'emergency_alert_id' => $alert->id,
            'timestamp' => $alert->created_at,
            'recommendation' => 'Open the patient dashboard and contact the patient immediately.',
        ];

        $careTeamUsers = $this->careTeamUsers($patient->id);
        $this->createInAppNotifications($careTeamUsers, $body, $payload, '/report/'.$patient->id);

        // RELIABILITY (RIVA-102): the alert row and in-app notifications above are
        // already saved at this point — that's the part the patient's success
        // response actually promises. dispatch() itself can still throw (queue
        // connection failure, serialization error, or — while QUEUE_CONNECTION
        // remains "sync" — the job's own body executing inline and throwing after
        // every channel fails). None of that should turn an already-successful
        // "alert saved, care team notified in-app" outcome into an HTTP 500. See
        // the cost/scalability audit §22 P0-2.
        try {
            SendEmergencyAlertNotifications::dispatch($alert->id);
        } catch (\Throwable $exception) {
            Log::critical('Failed to dispatch emergency alert delivery job — alert and in-app notifications were still saved.', [
                'emergency_alert_id' => $alert->id,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);
        }

        return $this->success([
            'alert' => $alert->fresh(),
        ], 'Emergency alert saved. Your care team has been notified in the app, and urgent email/WhatsApp delivery is in progress.', 201);
    }

    private function careTeamUsers(int $patientId): Collection
    {
        $caregivers = PatientCaregiverRelationship::with('caregiver.user')
            ->where('patient_id', $patientId)
            ->whereIn('status', ['active', 'accepted'])
            ->get()
            ->map(fn ($relationship) => $relationship->caregiver?->user)
            ->filter();

        $doctors = PatientDoctorRelationship::with('doctor.user')
            ->where('patient_id', $patientId)
            ->whereIn('status', ['active', 'accepted'])
            ->get()
            ->map(fn ($relationship) => $relationship->doctor?->user)
            ->filter();

        return $caregivers
            ->merge($doctors)
            ->unique('id')
            ->values();
    }

    private function createInAppNotifications(Collection $users, string $body, array $payload, string $actionUrl): void
    {
        foreach ($users as $user) {
            Notification::create([
                'notifiable_type' => User::class,
                'notifiable_id' => $user->id,
                'type' => 'emergency_alert',
                'title' => 'Emergency alert',
                'message' => $body,
                'data' => $payload,
                'action_url' => $actionUrl,
                'category' => 'emergency',
            ]);
        }

        Log::info('Emergency alert in-app notifications created', [
            'notifications_created' => $users->count(),
            'user_ids' => $users->pluck('id')->all(),
        ]);
    }
}
