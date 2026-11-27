<?php

namespace Tests\Feature;

use App\Jobs\SendMedicationReminder;
use App\Models\Medication;
use App\Models\MedicationLog;
use App\Models\Notification;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * RIVA-122: the pre-dose ("N minutes before") medication reminder previously
 * had no server-side trigger at all — only a client-side setTimeout in
 * dashboard-p.component.ts, armed once per dashboard page load and lost
 * entirely if the tab closed before it fired. This covers the new
 * medications:send-reminders scheduled command
 * (MedicationAdherenceService::dueSoonPendingLogs() + routes/console.php),
 * which sends the reminder regardless of whether any patient has the
 * dashboard open.
 */
class MedicationReminderSchedulerTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_send_reminders_command_dispatches_job_and_notification_for_a_dose_due_soon(): void
    {
        Bus::fake();
        Carbon::setTestNow(Carbon::parse('2026-01-01 08:00:00'));

        [$user, $patient] = $this->makePatient();
        $this->makeMedication($patient, '08:15', 'Metformin');

        Artisan::call('medications:send-reminders', ['--lead' => 30]);

        $log = MedicationLog::where('patient_id', $patient->id)->first();
        $this->assertNotNull($log);
        $this->assertNotNull($log->reminder_sent_at, 'reminder_sent_at should be stamped once a reminder is sent');

        Bus::assertDispatched(SendMedicationReminder::class, function (SendMedicationReminder $job) use ($user) {
            $ref = new \ReflectionClass($job);
            $userId = $ref->getProperty('userId');
            $userId->setAccessible(true);

            return $userId->getValue($job) === $user->id;
        });

        $this->assertSame(1, Notification::where('notifiable_id', $user->id)->where('type', 'medication_reminder')->count());
    }

    public function test_send_reminders_command_ignores_a_dose_outside_the_lead_window(): void
    {
        Bus::fake();
        Carbon::setTestNow(Carbon::parse('2026-01-01 08:00:00'));

        [, $patient] = $this->makePatient();
        // Due in 2 hours — well outside a 30-minute lead window.
        $this->makeMedication($patient, '10:00', 'Lisinopril');

        Artisan::call('medications:send-reminders', ['--lead' => 30]);

        Bus::assertNotDispatched(SendMedicationReminder::class);

        $log = MedicationLog::where('patient_id', $patient->id)->first();
        $this->assertNotNull($log);
        $this->assertNull($log->reminder_sent_at);
    }

    public function test_send_reminders_command_ignores_a_dose_already_past_due(): void
    {
        Bus::fake();
        Carbon::setTestNow(Carbon::parse('2026-01-01 08:00:00'));

        [, $patient] = $this->makePatient();
        // Already due 10 minutes ago — that's medications:mark-missed's job,
        // not a "coming up" reminder.
        $this->makeMedication($patient, '07:50', 'Atorvastatin');

        Artisan::call('medications:send-reminders', ['--lead' => 30]);

        Bus::assertNotDispatched(SendMedicationReminder::class);
    }

    public function test_send_reminders_command_does_not_duplicate_on_repeated_runs(): void
    {
        Bus::fake();
        Carbon::setTestNow(Carbon::parse('2026-01-01 08:00:00'));

        [, $patient] = $this->makePatient();
        $this->makeMedication($patient, '08:15', 'Metformin');

        Artisan::call('medications:send-reminders', ['--lead' => 30]);
        Artisan::call('medications:send-reminders', ['--lead' => 30]);
        Artisan::call('medications:send-reminders', ['--lead' => 30]);

        Bus::assertDispatchedTimes(SendMedicationReminder::class, 1);
    }

    public function test_send_reminders_command_creates_todays_dose_logs_even_if_dashboard_never_opened(): void
    {
        Bus::fake();
        Carbon::setTestNow(Carbon::parse('2026-01-01 08:00:00'));

        [, $patient] = $this->makePatient();
        $this->makeMedication($patient, '08:15', 'Metformin');

        // No prior call to ensureTodayLogs()/todaySchedule() for this patient
        // — simulating a patient who has never opened the dashboard today.
        $this->assertSame(0, MedicationLog::where('patient_id', $patient->id)->count());

        Artisan::call('medications:send-reminders', ['--lead' => 30]);

        $this->assertSame(1, MedicationLog::where('patient_id', $patient->id)->count());
        Bus::assertDispatchedTimes(SendMedicationReminder::class, 1);
    }

    private function makePatient(): array
    {
        $user = User::create([
            'first_name' => 'Reminder', 'last_name' => uniqid('Scheduler'),
            'name' => 'Reminder Scheduler', 'email' => uniqid('reminder-scheduler').'@riva.test',
            'password' => 'password', 'role' => 'patient', 'is_active' => true,
            'profile_completed_at' => now(),
        ]);

        return [$user, Patient::create(['user_id' => $user->id])];
    }

    private function makeMedication(Patient $patient, string $time, string $name = 'Aspirin'): Medication
    {
        return Medication::create([
            'patient_id' => $patient->id,
            'name' => $name,
            'dosage' => '100mg',
            'schedule_time' => $time,
            'frequency' => 'daily',
            'start_date' => today(),
            'is_active' => true,
        ]);
    }
}
