<?php

namespace Tests\Feature;

use App\Models\Caregiver;
use App\Models\Medication;
use App\Models\MedicationLog;
use App\Models\Notification;
use App\Models\Patient;
use App\Models\PatientCaregiverRelationship;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use App\Mail\MissedMedicationAlertMail;
use Tests\TestCase;

class MedicationSystemTest extends TestCase
{
    use RefreshDatabase;

    public function test_patient_can_create_medication(): void
    {
        [$user, $patient] = $this->makePatient();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/medications', [
            'drug_name' => 'Aspirin',
            'dosage' => '100mg',
            'schedule_time' => now()->format('H:i'),
            'frequency' => 'daily',
            'notes' => 'After food',
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Medication created successfully')
            ->assertJsonPath('data.drug_name', 'Aspirin');

        $this->assertDatabaseHas('medications', [
            'patient_id' => $patient->id,
            'name' => 'Aspirin',
            'dosage' => '100mg',
        ]);
    }

    public function test_patient_can_take_single_dose(): void
    {
        [$user, $patient] = $this->makePatient();
        Sanctum::actingAs($user);
        $medication = $this->makeMedication($patient, '08:00');

        $schedule = $this->getJson('/api/patient/medications/today')->json('data');
        $doseId = $schedule['pending_doses'][0]['id'];

        $response = $this->postJson("/api/patient/medications/doses/{$doseId}/take");

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.dose.status', 'taken');

        $this->assertDatabaseHas('medication_logs', [
            'id' => $doseId,
            'patient_id' => $patient->id,
            'medication_id' => $medication->id,
            'status' => 'taken',
        ]);
    }

    public function test_taking_one_dose_does_not_complete_day_if_other_doses_pending(): void
    {
        [$user, $patient] = $this->makePatient();
        Sanctum::actingAs($user);
        $this->makeMedication($patient, '08:00', 'Med One');
        $this->makeMedication($patient, '09:00', 'Med Two');

        $schedule = $this->getJson('/api/patient/medications/today')->json('data');
        $doseId = $schedule['pending_doses'][0]['id'];

        $response = $this->postJson("/api/patient/medications/doses/{$doseId}/take");
        $updated = $response->json('data.schedule');

        $this->assertFalse($updated['all_completed_today']);
        $this->assertCount(1, $updated['pending_doses']);
        $this->assertSame(50, (int) $updated['adherence_today']);
    }

    public function test_all_doses_taken_marks_day_complete(): void
    {
        [$user, $patient] = $this->makePatient();
        Sanctum::actingAs($user);
        $this->makeMedication($patient, '08:00', 'Med One');
        $this->makeMedication($patient, '09:00', 'Med Two');

        $schedule = $this->getJson('/api/patient/medications/today')->json('data');
        foreach ($schedule['pending_doses'] as $dose) {
            $this->postJson("/api/patient/medications/doses/{$dose['id']}/take")->assertOk();
        }

        $final = $this->getJson('/api/patient/medications/today')->json('data');
        $this->assertTrue($final['all_completed_today']);
        $this->assertCount(0, $final['pending_doses']);
        $this->assertCount(2, $final['completed_doses']);
    }

    public function test_missed_medication_marks_dose_missed_and_notifies_caregiver_once(): void
    {
        Mail::fake();
        [$user, $patient] = $this->makePatient();
        [, $caregiver] = $this->makeCaregiver();
        PatientCaregiverRelationship::create([
            'patient_id' => $patient->id,
            'caregiver_id' => $caregiver->id,
            'status' => 'active',
            'requested_at' => now()->subDay(),
            'responded_at' => now()->subDay(),
        ]);

        $this->makeMedication($patient, now()->subMinutes(40)->format('H:i'), 'Late Med');
        app(\App\Services\MedicationAdherenceService::class)->todaySchedule($patient);

        Artisan::call('medications:mark-missed', ['--grace' => 15]);

        $log = MedicationLog::where('patient_id', $patient->id)->whereHas('medication', fn ($query) => $query->where('name', 'Late Med'))->first();
        $this->assertSame('missed', $log->status);
        $this->assertNotNull($log->missed_at);
        $this->assertNotNull($log->missed_notification_sent_at);

        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $caregiver->user_id,
            'type' => 'medication',
            'title' => 'Missed Medication Alert',
            'category' => 'missed_medication',
        ]);
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $user->id,
            'type' => 'medication',
            'title' => 'Medication Missed',
            'category' => 'missed_medication',
        ]);
        $sentCount = Mail::sent(MissedMedicationAlertMail::class)->count();
        $this->assertGreaterThanOrEqual(1, $sentCount);

        $count = Notification::where('notifiable_id', $caregiver->user_id)
            ->where('type', 'medication')
            ->where('category', 'missed_medication')
            ->count();

        Artisan::call('medications:mark-missed', ['--grace' => 15]);

        $this->assertSame($count, Notification::where('notifiable_id', $caregiver->user_id)->where('type', 'medication')->where('category', 'missed_medication')->count());
        $this->assertSame($sentCount, Mail::sent(MissedMedicationAlertMail::class)->count());
    }

    public function test_pending_string_dose_is_marked_missed_using_app_timezone(): void
    {
        config(['app.timezone' => 'Africa/Cairo']);
        Mail::fake();
        [$user, $patient] = $this->makePatient();
        [, $caregiver] = $this->makeCaregiver();
        PatientCaregiverRelationship::create([
            'patient_id' => $patient->id,
            'caregiver_id' => $caregiver->id,
            'status' => 'active',
        ]);

        $medication = $this->makeMedication($patient, now('Africa/Cairo')->subMinutes(20)->format('H:i'), 'Cairo Time Med');
        $log = MedicationLog::create([
            'patient_id' => $patient->id,
            'medication_id' => $medication->id,
            'scheduled_at' => now('Africa/Cairo')->subMinutes(20),
            'scheduled_for_date' => today('Africa/Cairo'),
            'scheduled_time' => now('Africa/Cairo')->subMinutes(20)->format('H:i:s'),
            'status' => 'pending',
        ]);

        Artisan::call('medications:mark-missed', ['--grace' => 15]);

        $this->assertSame('missed', $log->fresh()->status);
        $this->assertNotNull($log->fresh()->missed_at);
        Mail::assertSent(MissedMedicationAlertMail::class);
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $caregiver->user_id,
            'title' => 'Missed Medication Alert',
            'category' => 'missed_medication',
        ]);
    }

    private function makePatient(): array
    {
        $user = User::create([
            'first_name' => 'Patient',
            'last_name' => uniqid('Test'),
            'name' => 'Patient Test',
            'email' => uniqid('patient').'@riva.test',
            'password' => 'password',
            'role' => 'patient',
            'is_active' => true,
        ]);

        $patient = Patient::create(['user_id' => $user->id]);

        return [$user, $patient];
    }

    private function makeCaregiver(): array
    {
        $user = User::create([
            'first_name' => 'Caregiver',
            'last_name' => uniqid('Test'),
            'name' => 'Caregiver Test',
            'email' => uniqid('caregiver').'@riva.test',
            'password' => 'password',
            'role' => 'caregiver',
            'is_active' => true,
        ]);

        $caregiver = Caregiver::create(['user_id' => $user->id]);

        return [$user, $caregiver];
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
