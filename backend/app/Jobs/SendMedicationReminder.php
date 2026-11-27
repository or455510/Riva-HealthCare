<?php

namespace App\Jobs;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * COST / SECURITY (RIVA-102): previously this delivery happened synchronously,
 * inline in MedicationReminderController::send() — a blocking Mail::raw() plus
 * a blocking Twilio HTTP call in the same request the user was waiting on. It
 * also had a hardcoded WhatsApp recipient ('whatsapp:+201060465845') instead of
 * the actual user's own phone number, meaning every reminder — regardless of
 * which user triggered it — was sent to the same fixed number. See the
 * cost/scalability audit §22 P0-5.
 *
 * This job fixes both: delivery now happens out-of-band (queued), and the
 * WhatsApp recipient is the user's own phone number (skipped, not silently
 * misdelivered, if the user has none on file).
 */
class SendMedicationReminder implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> seconds to wait before each retry */
    public array $backoff = [15, 60, 180];

    public function __construct(
        private readonly int $userId,
        private readonly string $medicationName,
        private readonly string $scheduleTime,
    ) {}

    public function handle(): void
    {
        $user = User::find($this->userId);

        if (! $user) {
            Log::warning('SendMedicationReminder: user no longer exists, skipping.', [
                'user_id' => $this->userId,
            ]);

            return;
        }

        $message = "💊 Reminder: Please take {$this->medicationName} at {$this->scheduleTime}.";

        if ($user->email) {
            try {
                Mail::raw($message, function ($mail) use ($user) {
                    $mail->to($user->email)->subject('💊 Medication Reminder - RIVA');
                });
            } catch (Throwable $exception) {
                Log::warning('Medication reminder email failed', [
                    'user_id' => $user->id,
                    'exception' => $exception::class,
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        $this->sendWhatsApp($user, $message);
    }

    private function sendWhatsApp(User $user, string $message): void
    {
        $sid = config('services.twilio.sid');
        $token = config('services.twilio.token');
        $from = config('services.twilio.from');

        if (! $sid || ! $token || ! $from || ! $user->phone) {
            Log::info('Medication reminder WhatsApp skipped (not configured or user has no phone on file)', [
                'user_id' => $user->id,
                'has_twilio_config' => (bool) ($sid && $token && $from),
                'has_phone' => (bool) $user->phone,
            ]);

            return;
        }

        try {
            $response = Http::timeout(15)
                ->connectTimeout(5)
                ->withBasicAuth($sid, $token)
                ->asForm()
                ->post("https://api.twilio.com/2010-04-01/Accounts/{$sid}/Messages.json", [
                    'From' => 'whatsapp:'.$from,
                    'To' => 'whatsapp:'.$user->phone,
                    'Body' => $message,
                ]);

            if ($response->failed()) {
                Log::warning('Medication reminder WhatsApp failed', [
                    'user_id' => $user->id,
                    'status' => $response->status(),
                ]);
            }
        } catch (Throwable $exception) {
            Log::warning('Medication reminder WhatsApp failed', [
                'user_id' => $user->id,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);
        }
    }
}
