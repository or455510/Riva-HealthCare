<?php

namespace App\Jobs;

use App\Models\EmergencyAlert;
use App\Models\PatientCaregiverRelationship;
use App\Models\PatientDoctorRelationship;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * RELIABILITY (Phase 0 / S1): external delivery (email + WhatsApp) for an emergency
 * alert used to happen synchronously, once, inline in the HTTP request — if both
 * channels failed (as actually happened in production per storage/logs/laravel.log:
 * a DNS resolution failure followed by an SSL verify failure on the very next
 * attempt), the patient still received an HTTP 201 "alert saved" response with no
 * further attempt and no visible escalation beyond a warning-level log line.
 *
 * This job separates concerns: the alert row and in-app notifications to the care
 * team are created synchronously in the controller (fast, DB-only, always reliable)
 * before this job is even dispatched, so the patient-visible confirmation never
 * depends on any external service. This job then handles the two external channels
 * with real retry/backoff, and escalates to platform admins (in-app, not just a log
 * line) only once every retry has been exhausted.
 *
 * NOTE: retry/backoff only take effect when QUEUE_CONNECTION is a real queue driver
 * (e.g. "database" or "redis") with a worker running. With QUEUE_CONNECTION=sync
 * (the current default in .env), this job still runs inline in the request and a
 * failure is not retried later — switching the queue driver is a required, low-cost
 * deployment change (see the engineering report) for this fix to fully take effect.
 */
class SendEmergencyAlertNotifications implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> seconds to wait before each retry */
    public array $backoff = [30, 120, 300];

    public function __construct(private readonly int $emergencyAlertId) {}

    public function handle(): void
    {
        $alert = EmergencyAlert::with('patient.user')->find($this->emergencyAlertId);

        if (! $alert) {
            Log::warning('SendEmergencyAlertNotifications: alert no longer exists, skipping.', [
                'emergency_alert_id' => $this->emergencyAlertId,
            ]);

            return;
        }

        $alert->increment('delivery_attempts');

        $patient = $alert->patient;
        $dashboardLink = rtrim((string) config('services.frontend.url'), '/').'/report/'.$patient->id;

        $careTeamUsers = $this->careTeamUsers($patient->id);
        $emailRecipients = $this->emailRecipients($careTeamUsers);
        $emailResults = $this->sendUrgentEmails($alert, $emailRecipients, $dashboardLink);

        if (($emailResults[strtolower((string) config('services.emergency.admin_email'))] ?? false) === true) {
            $alert->forceFill(['admin_email_sent_at' => now()])->save();
        }

        $whatsappSent = $this->sendWhatsAppNotification($alert, $dashboardLink);

        $anyChannelSucceeded = in_array(true, $emailResults, true) || $whatsappSent;

        Log::info('Emergency alert delivery attempt completed', [
            'emergency_alert_id' => $alert->id,
            'attempt' => $alert->delivery_attempts,
            'email_results' => $emailResults,
            'whatsapp_sent' => $whatsappSent,
        ]);

        if (! $anyChannelSucceeded) {
            // Every channel failed on this attempt — throw so the queue's retry/backoff
            // takes over. If this was the final allowed try, failed() below fires.
            throw new \RuntimeException(
                "Emergency alert #{$alert->id}: every delivery channel failed on attempt {$alert->delivery_attempts}."
            );
        }
    }

    public function failed(?Throwable $exception): void
    {
        $alert = EmergencyAlert::with('patient.user')->find($this->emergencyAlertId);

        if (! $alert) {
            return;
        }

        $alert->forceFill(['delivery_failed_at' => now()])->save();

        Log::critical('Emergency alert delivery permanently failed after all retries.', [
            'emergency_alert_id' => $alert->id,
            'patient_id' => $alert->patient_id,
            'attempts' => $alert->delivery_attempts,
            'exception' => $exception?->getMessage(),
        ]);

        // Escalate in-app to every admin, not just a log line — logs are not
        // monitored in real time by anyone today (Phase 1 audit finding).
        $notificationService = app(NotificationService::class);
        $patientName = $alert->patient?->user?->name ?: 'A patient';

        User::where('role', 'admin')->get()->each(function (User $admin) use ($notificationService, $alert, $patientName) {
            $notificationService->create(
                $admin,
                'emergency_alert_delivery_failed',
                'Emergency alert delivery failed',
                "Automated email/WhatsApp delivery for {$patientName}'s emergency alert (#{$alert->id}) failed after every retry. Check on this patient directly.",
                ['emergency_alert_id' => $alert->id, 'patient_id' => $alert->patient_id],
                '/admin/emergency-alerts/'.$alert->id,
                'emergency'
            );
        });
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

        return $caregivers->merge($doctors)->unique('id')->values();
    }

    private function emailRecipients(Collection $careTeamUsers): array
    {
        $adminEmail = (string) config('services.emergency.admin_email');
        $recipients = $adminEmail !== '' ? [strtolower($adminEmail) => $adminEmail] : [];

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
        $patient = $alert->patient;
        $user = $patient?->user;
        $patientName = $user?->name ?: $user?->full_name ?: 'Riva Patient';
        $patientContact = trim(($user?->email ?: 'No email recorded').' / '.($user?->phone ?: 'No phone recorded'));
        $details = trim(($alert->symptoms ?: '')."\n".($alert->message ?: ''));

        $body = implode("\n", [
            'URGENT: Emergency Alert from Riva Patient',
            '',
            'Patient name: '.$patientName,
            'Patient email/phone: '.$patientContact,
            'Pain level: '.($alert->pain_level !== null ? $alert->pain_level.'/10' : 'Not provided'),
            'Symptoms/message: '.($details ?: 'No details provided'),
            'Time: '.$alert->created_at?->format('Y-m-d H:i:s'),
            'Dashboard link: '.$dashboardLink,
        ]);

        foreach ($recipients as $key => $recipient) {
            try {
                Mail::raw($body, function ($mail) use ($recipient, $user) {
                    $mail->to($recipient)->subject('URGENT: Emergency Alert from Riva Patient');

                    if ($user?->email) {
                        $mail->replyTo($user->email, $user->name ?: $user->full_name ?: 'Riva Patient');
                    }
                });

                $results[$key] = true;
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
        $adminWhatsapp = config('services.emergency.admin_whatsapp');

        if (! $sid || ! $token || ! $from || ! $adminWhatsapp) {
            Log::warning('Emergency alert WhatsApp not configured', [
                'emergency_alert_id' => $alert->id,
                'has_sid' => (bool) $sid,
                'has_token' => (bool) $token,
                'has_from' => (bool) $from,
                'has_admin_whatsapp' => (bool) $adminWhatsapp,
            ]);

            return false;
        }

        $patientName = $alert->patient?->user?->name ?: 'Riva Patient';
        $body = implode("\n", [
            'URGENT Riva emergency alert',
            'Patient: '.$patientName,
            'Pain: '.($alert->pain_level !== null ? $alert->pain_level.'/10' : 'Not provided'),
            'Details: '.mb_substr(trim(($alert->symptoms ?: '').' '.($alert->message ?: '')), 0, 1200),
            'Time: '.$alert->created_at?->format('Y-m-d H:i:s'),
            'Dashboard: '.$dashboardLink,
        ]);

        try {
            $response = Http::timeout(15)
                ->connectTimeout(5)
                ->withBasicAuth($sid, $token)
                ->asForm()
                ->post("https://api.twilio.com/2010-04-01/Accounts/{$sid}/Messages.json", [
                    'From' => $this->whatsAppAddress($from),
                    'To' => $this->whatsAppAddress($adminWhatsapp),
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

        return true;
    }

    private function whatsAppAddress(string $number): string
    {
        return str_starts_with($number, 'whatsapp:') ? $number : 'whatsapp:'.$number;
    }
}
