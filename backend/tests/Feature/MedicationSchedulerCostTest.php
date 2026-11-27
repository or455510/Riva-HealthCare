<?php

namespace Tests\Feature;

use App\Models\Medication;
use App\Models\MedicationLog;
use App\Models\Patient;
use App\Models\User;
use App\Services\MedicationAdherenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * COST (RIVA-103) regression coverage for the medications:mark-missed
 * scheduled command — see routes/console.php and
 * MedicationAdherenceService::ensureTodayLogs() for the fix, and the
 * cost/scalability audit §22 P0-3 for the finding this closes.
 */
class MedicationSchedulerCostTest extends TestCase
{
    use RefreshDatabase;

    public function test_ensure_today_logs_creates_dose_logs_without_computing_the_weekly_payload(): void
    {
        [, $patient] = $this->makePatient();
        $this->makeMedication($patient, '08:00');

        $this->assertSame(0, MedicationLog::where('patient_id', $patient->id)->count());

        app(MedicationAdherenceService::class)->ensureTodayLogs($patient);

        $this->assertSame(1, MedicationLog::where('patient_id', $patient->id)->count());
    }

    public function test_mark_missed_command_creates_dose_logs_for_every_patient_with_an_active_medication(): void
    {
        // Multiple patients, none of whom have ever had todaySchedule()/
        // ensureTodayLogs() called for them yet — the command's own
        // chunked pre-pass must create today's dose logs from scratch.
        foreach (range(1, 3) as $i) {
            [, $patient] = $this->makePatient();
            $this->makeMedication($patient, '08:00', "Med {$i}");
        }

        $this->assertSame(0, MedicationLog::count());

        Artisan::call('medications:mark-missed', ['--grace' => 15]);

        $this->assertSame(3, MedicationLog::count());
    }

    public function test_mark_missed_command_does_not_duplicate_logs_on_repeated_runs(): void
    {
        [, $patient] = $this->makePatient();
        $this->makeMedication($patient, '08:00');

        Artisan::call('medications:mark-missed', ['--grace' => 15]);
        Artisan::call('medications:mark-missed', ['--grace' => 15]);
        Artisan::call('medications:mark-missed', ['--grace' => 15]);

        $this->assertSame(1, MedicationLog::where('patient_id', $patient->id)->count());
    }

    public function test_mark_missed_command_query_count_does_not_scale_with_a_seven_day_lookback_per_patient(): void
    {
        // Regression guard for the fixed bug: the old todaySchedule()-based
        // pre-pass ran a weekday_statuses() loop (7 days × ensureDoseLogsForDate)
        // per patient — for 5 patients that meant on the order of dozens of
        // queries just for the pre-pass, before duePendingLogs() even ran.
        // With ensureTodayLogs(), each patient should cost roughly a handful
        // of queries (one Medication lookup + one MedicationLog existence
        // check per medication + one final fetch), not 7x that.
        foreach (range(1, 5) as $i) {
            [, $patient] = $this->makePatient();
            $this->makeMedication($patient, '08:00', "Med {$i}");
        }

        DB::enableQueryLog();
        Artisan::call('medications:mark-missed', ['--grace' => 15]);
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Measured at ~15 queries/patient for this fixture (Medication lookup
        // + per-time MedicationLog existence check + final fetch, per
        // ensureDoseLogsForDate call — ensureTodayLogs() calls it exactly
        // once per patient). The old todaySchedule()-based path called the
        // same private method up to 8 times per patient (once for "today",
        // then once per non-future day in weekday_statuses()'s 7-day loop),
        // which would put this fixture's count well over 400. This is a
        // loose ceiling, not an exact count — the point is bounding growth
        // to roughly 1x per-patient cost, not 7-8x.
        $this->assertLessThan(120, $queryCount, "Expected a bounded query count, got {$queryCount}.");
    }

    private function makePatient(): array
    {
        $user = User::create([
            'first_name' => 'Scheduler', 'last_name' => uniqid('Cost'),
            'name' => 'Scheduler Cost', 'email' => uniqid('scheduler').'@riva.test',
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
