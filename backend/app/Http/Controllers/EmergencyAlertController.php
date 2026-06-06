<?php

namespace App\Http\Controllers;

use App\Models\EmergencyAlert;
use App\Models\Notification;
use App\Models\PatientCaregiverRelationship;
use App\Models\PatientDoctorRelationship;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Http\Request;
use Throwable;

class EmergencyAlertController extends Controller
{
    private const ADMIN_EMAIL = 'or455510@gmail.com';
    private const ADMIN_WHATSAPP = '+201012077945';

    public function store(Request $request)
    {
        $startedAt = microtime(true);
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

        Log::info('Emergency alert saved', [
            'emergency_alert_id' => $alert->id,
            'patient_id' => $patient->id,
            'severity' => $alert->severity,
            'pain_level' => $alert->pain_level,
        ]);

        $patientName = $patient->user?->name ?: $patient->user?->full_name ?: 'A patient';
        $conditions = $patient->chronic_conditions ?: 'No chronic condition recorded';
        $body = "Emergency alert: {$patientName} reported {$alertMessage}. Condition: {$conditions}. Severity: {$alert->severity}. Submitted {$alert->created_at->format('g:i A')}.";
        $dashboardLink = rtrim((string) config('services.frontend.url'), '/') . '/report/' . $patient->id;

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
        $this->createInAppNotifications($careTeamUsers, $body, $payload, '/report/' . $patient->id);

        $emailRecipients = $this->emailRecipients($careTeamUsers);
        $emailResults = $this->sendUrgentEmails($alert, $emailRecipients, $dashboardLink);

        if (($emailResults[self::ADMIN_EMAIL] ?? false) === true) {
            $alert->forceFill(['admin_email_sent_at' => now()])->save();
        }

        $whatsappSent = $this->sendWhatsAppNotification($alert, $dashboardLink);

        Log::info('Emergency alert request completed', [
            'emergency_alert_id' => $alert->id,
            'patient_id' => $patient->id,
            'email_recipients' => array_keys($emailRecipients),
            'email_results' => $emailResults,
            'whatsapp_sent' => $whatsappSent,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);

        $message = $whatsappSent
            ? 'Emergency alert saved and urgent email/WhatsApp notifications were attempted.'
            : 'Emergency alert saved and urgent email notifications were attempted. WhatsApp not configured or failed.';

        return $this->success([
            'alert' => $alert->fresh(),
            'email_recipients' => array_keys($emailRecipients),
            'email_results' => $emailResults,
            'whatsapp_sent' => $whatsappSent,
        ], $message, 201);
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

    private function emailRecipients(Collection $careTeamUsers): array
    {
        $recipients = [strtolower(self::ADMIN_EMAIL) => self::ADMIN_EMAIL];

        foreach ($careTeamUsers as $user) {
            if ($user->email) {
                $recipients[strtolower($user->email)] = $user->email;
            }
        }

        return $recipients;
    }

    private function sendUrgentEmails(EmergencyAlert $alert, array $recipients, string $dashboardLink): array
    {
        $results = [];
        $patient = $alert->patient()->with('user')->first();
        $user = $patient?->user;
        $patientName = $user?->name ?: $user?->full_name ?: 'Riva Patient';
        $patientContact = trim(($user?->email ?: 'No email recorded') . ' / ' . ($user?->phone ?: 'No phone recorded'));
        $details = trim(($alert->symptoms ?: '') . "\n" . ($alert->message ?: ''));

        $body = implode("\n", [
            'URGENT: Emergency Alert from Riva Patient',
            '',
            'Patient name: ' . $patientName,
            'Patient email/phone: ' . $patientContact,
            'Pain level: ' . ($alert->pain_level !== null ? $alert->pain_level . '/10' : 'Not provided'),
            'Symptoms/message: ' . ($details ?: 'No details provided'),
            'Time: ' . $alert->created_at?->format('Y-m-d H:i:s'),
            'Dashboard link: ' . $dashboardLink,
        ]);

        foreach ($recipients as $key => $recipient) {
            try {
                Mail::raw($body, function ($mail) use ($recipient, $user) {
                    $mail->to($recipient)
                        ->subject('URGENT: Emergency Alert from Riva Patient');

                    if ($user?->email) {
                        $mail->replyTo($user->email, $user->name ?: $user->full_name ?: 'Riva Patient');
                    }
                });

                $results[$key] = true;

                Log::info('Emergency alert urgent email sent', [
                    'emergency_alert_id' => $alert->id,
                    'recipient' => $recipient,
                ]);
            } catch (Throwable $exception) {
                $results[$key] = false;

                Log::warning('Emergency alert urgent email failed', [
                    'emergency_alert_id' => $alert->id,
                    'recipient' => $recipient,
                    'exception' => $exception::class,
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        return $results;
    }

    private function sendWhatsAppNotification(EmergencyAlert $alert, string $dashboardLink): bool
    {
        $sid = config('services.twilio.sid');
        $token = config('services.twilio.token');
        $from = config('services.twilio.from');

        if (!$sid || !$token || !$from) {
            Log::warning('Emergency alert WhatsApp not configured', [
                'emergency_alert_id' => $alert->id,
                'has_sid' => (bool) $sid,
                'has_token' => (bool) $token,
                'has_from' => (bool) $from,
            ]);

            return false;
        }

        $patient = $alert->patient()->with('user')->first();
        $patientName = $patient?->user?->name ?: $patient?->user?->full_name ?: 'Riva Patient';
        $body = implode("\n", [
            'URGENT Riva emergency alert',
            'Patient: ' . $patientName,
            'Pain: ' . ($alert->pain_level !== null ? $alert->pain_level . '/10' : 'Not provided'),
            'Details: ' . mb_substr(trim(($alert->symptoms ?: '') . ' ' . ($alert->message ?: '')), 0, 1200),
            'Time: ' . $alert->created_at?->format('Y-m-d H:i:s'),
            'Dashboard: ' . $dashboardLink,
        ]);

        try {
            $response = Http::timeout(15)
                ->connectTimeout(5)
                ->withBasicAuth($sid, $token)
                ->asForm()
                ->post("https://api.twilio.com/2010-04-01/Accounts/{$sid}/Messages.json", [
                    'From' => $this->whatsAppAddress($from),
                    'To' => $this->whatsAppAddress(self::ADMIN_WHATSAPP),
                    'Body' => $body,
                ]);
        } catch (Throwable $exception) {
            Log::warning('Emergency alert WhatsApp failed', [
                'emergency_alert_id' => $alert->id,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return false;
        }

        if ($response->failed()) {
            Log::warning('Emergency alert WhatsApp failed', [
                'emergency_alert_id' => $alert->id,
                'status' => $response->status(),
                'body' => $response->json() ?? $response->body(),
            ]);

            return false;
        }

        $alert->forceFill(['whatsapp_sent_at' => now()])->save();

        Log::info('Emergency alert WhatsApp sent', [
            'emergency_alert_id' => $alert->id,
            'admin_whatsapp' => self::ADMIN_WHATSAPP,
            'twilio_sid' => data_get($response->json(), 'sid'),
        ]);

        return true;
    }

    private function whatsAppAddress(string $number): string
    {
        return str_starts_with($number, 'whatsapp:') ? $number : 'whatsapp:' . $number;
    }
}
