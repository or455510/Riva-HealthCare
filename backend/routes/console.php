<?php

use App\Jobs\SendMedicationReminder;
use App\Mail\MissedMedicationAlertMail;
use App\Models\MedicationLog;
use App\Models\Patient;
use App\Models\PatientCaregiverRelationship;
use App\Models\User;
use App\Services\MedicationAdherenceService;
use App\Services\NotificationService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('medications:mark-missed {--grace=}', function (MedicationAdherenceService $adherence, NotificationService $notifications) {
    $grace = (int) ($this->option('grace') ?? env('MEDICATION_MISSED_GRACE_MINUTES', 15));

    // COST (RIVA-103): previously ->get()->each(todaySchedule(...)) — loaded
    // every patient with an active medication into memory unchunked, and
    // todaySchedule() rebuilds a full 7-day weekly schedule payload (itself
    // re-running dose-log-ensure per day) that this command immediately
    // discarded. ensureTodayLogs() does only the part this command actually
    // needs (today's dose-log rows exist), and chunkById bounds memory/query
    // size regardless of how many patients have an active medication. This
    // runs every minute forever (see the Schedule::command call below), so it
    // was the single fastest-growing recurring compute cost identified in the
    // cost/scalability audit — see §22 P0-3. The 15-minute grace-period
    // behavior below (duePendingLogs) is unchanged.
    Patient::whereHas('medications', fn ($query) => $query->where('is_active', true))
        ->chunkById(200, function ($patients) use ($adherence) {
            foreach ($patients as $patient) {
                $adherence->ensureTodayLogs($patient);
            }
        });

    $logs = $adherence->duePendingLogs(null, $grace);
    $notified = 0;

    foreach ($logs as $log) {
        $updated = $adherence->confirmDose($log, 'missed', null);
        if ($updated->missed_notification_sent_at) {
            continue;
        }

        $updated->loadMissing(['patient.user', 'medication']);
        $patient = $updated->patient;
        $patientName = $patient?->user?->name ?: 'Patient';
        $name = $updated->medication?->name ?: 'A scheduled medication';
        $time = substr((string) $updated->scheduled_time, 0, 5);
        $dosage = $updated->medication?->dosage ?: 'Not specified';
        $caregiverMessage = "{$patientName} missed {$name} scheduled at {$time}.";
        $patientMessage = "You missed {$name} scheduled at {$time}.";
        $emailBody = implode("\n", [
            "Patient: {$patientName}",
            "Medication: {$name}",
            "Dosage: {$dosage}",
            "Scheduled time: {$time}",
            '',
            'Please check on this patient as soon as possible.',
        ]);
        $caregiverCount = 0;

        if ($patient?->user) {
            $notifications->create(
                $patient->user,
                'medication',
                'Medication Missed',
                $patientMessage,
                [
                    'related_type' => MedicationLog::class,
                    'related_id' => $updated->id,
                    'category' => 'missed_medication',
                ],
                null,
                'missed_medication'
            );
        }

        PatientCaregiverRelationship::with('caregiver.user')
            ->where('patient_id', $patient->id)
            ->whereIn('status', ['active', 'accepted'])
            ->get()
            ->each(function ($relationship) use ($notifications, $updated, $caregiverMessage, $emailBody, &$caregiverCount) {
                $user = $relationship->caregiver?->user;
                if (! $user) {
                    return;
                }

                $notifications->create(
                    $user,
                    'medication',
                    'Missed Medication Alert',
                    $caregiverMessage,
                    [
                        'related_type' => MedicationLog::class,
                        'related_id' => $updated->id,
                        'category' => 'missed_medication',
                    ],
                    null,
                    'missed_medication'
                );

                try {
                    Mail::to($user->email)->send(new MissedMedicationAlertMail($emailBody));
                } catch (Throwable $exception) {
                    Log::warning('Missed medication email failed after dashboard notification was created', [
                        'user_id' => $user->id,
                        'medication_log_id' => $updated->id,
                        'error' => $exception->getMessage(),
                    ]);
                }
                $caregiverCount++;
            });

        if ($caregiverCount === 0) {
            Log::warning('Missed medication has no assigned caregiver', [
                'patient_id' => $patient->id,
                'medication_log_id' => $updated->id,
            ]);

            User::where('role', 'admin')->get()->each(fn (User $admin) => $notifications->create(
                $admin,
                'medication',
                'Missed Medication Alert',
                "No caregiver assigned for missed medication alert. {$caregiverMessage}",
                [
                    'related_type' => MedicationLog::class,
                    'related_id' => $updated->id,
                    'category' => 'missed_medication',
                ],
                null,
                'missed_medication'
            ));
        }

        $updated->forceFill(['missed_notification_sent_at' => now(config('app.timezone'))])->save();
        $notified++;
    }

    $this->info("Marked {$logs->count()} medication dose(s) as missed. Sent {$notified} notification batch(es). Grace: {$grace} minutes.");
})->purpose('Mark overdue medication doses as missed and notify care team once');

// COST (RIVA-103): withoutOverlapping() prevents a slow run (e.g. a large
// patient backlog, a slow DB moment) from stacking a second concurrent
// invocation on top of the first every time this fires — see the
// cost/scalability audit §22 P0-3.
Schedule::command('medications:mark-missed')->everyMinute()->withoutOverlapping();

/**
 * RIVA-122: proactive "N minutes before a dose is due" reminder, run
 * server-side regardless of whether any patient has their dashboard open.
 *
 * Previously the only implementation of this feature was a client-side
 * setTimeout in Angular's dashboard-p.component.ts: computed once when the
 * patient dashboard loaded, and silently lost — no reminder, no error, no
 * retry — if the tab was closed, refreshed, or the patient simply never
 * opened the dashboard before the scheduled dose time. This command makes
 * the reminder a real, durable, server-triggered event: it ensures today's
 * dose-log rows exist independent of dashboard visits, finds doses due
 * within the lead window that haven't been reminded about yet, records an
 * in-app notification, and dispatches the same already-hardened
 * SendMedicationReminder job (RIVA-102: queued, real user's own email/phone,
 * retried with backoff) that the client-triggered
 * POST /medication-reminder/send endpoint uses. The frontend's setTimeout
 * path is left in place as a redundant, best-effort immediate in-tab toast
 * for a patient actively viewing the dashboard at the right moment — but
 * delivery of the actual reminder no longer depends on it.
 */
Artisan::command('medications:send-reminders {--lead=}', function (MedicationAdherenceService $adherence, NotificationService $notifications) {
    $lead = (int) ($this->option('lead') ?? env('MEDICATION_REMINDER_LEAD_MINUTES', 30));

    // Same cost-conscious pattern as medications:mark-missed (RIVA-103):
    // chunked, and only ensures today's rows exist rather than building a
    // full schedule payload per patient.
    Patient::whereHas('medications', fn ($query) => $query->where('is_active', true))
        ->chunkById(200, function ($patients) use ($adherence) {
            foreach ($patients as $patient) {
                $adherence->ensureTodayLogs($patient);
            }
        });

    $logs = $adherence->dueSoonPendingLogs(null, $lead);
    $sent = 0;

    foreach ($logs as $log) {
        if ($log->reminder_sent_at) {
            continue;
        }

        $log->loadMissing(['patient.user', 'medication']);
        $user = $log->patient?->user;

        if (! $user) {
            Log::warning('Medication reminder skipped: dose log has no linked user', [
                'medication_log_id' => $log->id,
                'patient_id' => $log->patient_id,
            ]);
            $log->forceFill(['reminder_sent_at' => now(config('app.timezone'))])->save();

            continue;
        }

        $name = $log->medication?->name ?: 'your medication';
        $time = substr((string) $log->scheduled_time, 0, 5);

        $notifications->create(
            $user,
            'medication_reminder',
            '💊 Medication Reminder',
            "Time to take {$name} in {$lead} minutes (due at {$time}).",
            [
                'related_type' => MedicationLog::class,
                'related_id' => $log->id,
                'category' => 'medication_reminder',
            ],
            null,
            'medication_reminder'
        );

        SendMedicationReminder::dispatch($user->id, $name, $time);

        $log->forceFill(['reminder_sent_at' => now(config('app.timezone'))])->save();
        $sent++;
    }

    $this->info("Found {$logs->count()} dose(s) due within {$lead} minute(s). Sent {$sent} reminder(s).");
})->purpose('Proactively remind patients before a pending medication dose is due');

// Runs every 5 minutes rather than every minute (unlike mark-missed, which
// checks for doses already overdue and so needs finer granularity): the
// reminder_sent_at guard makes this idempotent regardless of cadence, and a
// coarser interval matches the cost/scalability discipline established in
// RIVA-103 for this same recurring-command family.
Schedule::command('medications:send-reminders')->everyFiveMinutes()->withoutOverlapping();
