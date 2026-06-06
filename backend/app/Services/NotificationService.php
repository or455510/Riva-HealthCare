<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\User;
use App\Mail\GenericNotificationMail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class NotificationService
{
    public function create(
        User $user,
        string $type,
        string $title,
        string $message,
        mixed $data = null,
        mixed $actionUrl = null,
        mixed $category = null
    ): Notification {
        [$data, $actionUrl, $category] = $this->normalizeLegacyPayload($type, $data, $actionUrl, $category);

        $notification = Notification::create([
            'notifiable_type' => User::class,
            'notifiable_id' => $user->id,
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'data' => $data,
            'action_url' => $actionUrl,
            'category' => $category ?? $this->categorizeType($type),
        ]);

        if ($this->shouldEmail($type)) {
            try {
                $this->sendEmail($user, $title, $message, $actionUrl);
                Log::info('Critical notification email sent', [
                    'user_id' => $user->id,
                    'notification_id' => $notification->id,
                    'type' => $type,
                    'recipient' => $user->email,
                ]);
            } catch (\Throwable $exception) {
                Log::warning('Critical notification email failed after database notification was created', [
                    'user_id' => $user->id,
                    'notification_id' => $notification->id,
                    'type' => $type,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return $notification;
    }

    public function notify(
        User $user,
        string $type,
        string $title,
        string $message,
        ?array $data = null,
        ?string $actionUrl = null,
        bool $sendEmail = false
    ): Notification {
        $notification = $this->create($user, $type, $title, $message, $data, $actionUrl);

        if ($sendEmail && !$this->shouldEmail($type)) {
            try {
                $this->sendEmail($user, $title, $message, $actionUrl);
                Log::info('Notification email sent', [
                    'user_id' => $user->id,
                    'notification_id' => $notification->id,
                    'type' => $type,
                    'recipient' => $user->email,
                ]);
            } catch (\Throwable $exception) {
                Log::warning('Notification email failed after database notification was created', [
                    'user_id' => $user->id,
                    'notification_id' => $notification->id,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return $notification;
    }

    public function getUserNotifications(User $user, int $limit = 20)
    {
        return $user->notifications()
            ->latest()
            ->paginate($limit);
    }

    public function markAllAsRead(User $user): void
    {
        $user->notifications()
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    private function categorizeType(string $type): string
    {
        return match ($type) {
            'follow_request_accepted', 'new_follow_request' => 'follows',
            'new_appointment', 'appointment_pending_payment', 'appointment_confirmed', 'payment_success', 'appointment_reminder', 'appointment_cancelled' => 'appointment',
            'medication_reminder', 'medication_missed', 'missed_medication' => 'medication',
            'daily_report_reminder', 'daily_status_risk', 'patient_risk_alert', 'emergency_alert' => 'emergency',
            'doctor_comment', 'report.comment' => 'reports',
            'report.created' => 'reports',
            'new_review' => 'reviews',
            'password_reset', 'account_verification', 'password_changed' => 'account',
            'security_alert', 'login_alert' => 'security',
            'welcome' => 'system',
            default => 'general',
        };
    }

    private function shouldEmail(string $type): bool
    {
        return in_array($type, [
            'emergency_alert',
            'appointment_confirmed',
            'new_appointment',
            'report.created',
            'report.comment',
            'new_follow_request',
            'caregiver_follow_request',
            'medication_missed',
            'missed_medication',
            'daily_status_risk',
            'patient_risk_alert',
        ], true);
    }

    private function sendEmail(User $user, string $title, string $message, ?string $actionUrl = null): void
    {
        Mail::to($user->email)->send(new GenericNotificationMail($title, $message, $actionUrl));
    }

    private function normalizeLegacyPayload(string $type, mixed $data, mixed $actionUrl, mixed $category): array
    {
        if (is_string($data) && is_string($actionUrl) && (is_int($category) || ctype_digit((string) $category))) {
            return [
                [
                    'severity' => $data,
                    'related_type' => $actionUrl,
                    'related_id' => (int) $category,
                ],
                null,
                $this->categorizeType($type),
            ];
        }

        if (is_string($data) && $actionUrl === null && $category === null) {
            return [['severity' => $data], null, $this->categorizeType($type)];
        }

        return [
            is_array($data) ? $data : null,
            is_string($actionUrl) ? $actionUrl : null,
            is_string($category) ? $category : null,
        ];
    }
}
