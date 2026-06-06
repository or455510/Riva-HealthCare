<?php

use Illuminate\Foundation\Inspiring;
use App\Mail\MissedMedicationAlertMail;
use App\Models\MedicationLog;
use App\Models\Patient;
use App\Models\PatientCaregiverRelationship;
use App\Models\User;
use App\Services\MedicationAdherenceService;
use App\Services\NotificationService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('medications:mark-missed {--grace=}', function (MedicationAdherenceService $adherence, NotificationService $notifications) {
    $grace = (int) ($this->option('grace') ?? env('MEDICATION_MISSED_GRACE_MINUTES', 15));

    Patient::whereHas('medications', fn ($query) => $query->where('is_active', true))
        ->get()
        ->each(fn (Patient $patient) => $adherence->todaySchedule($patient));

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
                if (!$user) {
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
                } catch (\Throwable $exception) {
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

Schedule::command('medications:mark-missed')->everyMinute();
