<?php

namespace Tests\Feature;

use App\Jobs\SendEmergencyAlertNotifications;
use App\Models\Caregiver;
use App\Models\EmergencyAlert;
use App\Models\Patient;
use App\Models\PatientCaregiverRelationship;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * RELIABILITY (Phase 0) regression coverage for SendEmergencyAlertNotifications.
 * Closes the test-coverage gap flagged in the Phase 0 verification report — the job
 * shipped with no test asserting it is actually dispatched, that it retries on total
 * delivery failure, or that failed() escalates to admins once retries are exhausted.
 *
 * Twilio is always faked here (Http::fake()) even on the "success" path — this suite
 * must never make a real network call to a third-party API.
 */
class EmergencyAlertJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_dispatches_the_notification_job_with_the_new_alert_id(): void
    {
        Queue::fake();

        [$patientUser] = $this->makePatient();
        Sanctum::actingAs($patientUser);

        $this->postJson('/api/emergency-alerts', [
            'severity' => 'critical',
            'message' => 'Chest pain',
            'pain_level' => 9,
        ])->assertCreated();

        $alertId = EmergencyAlert::sole()->id;

        Queue::assertPushed(SendEmergencyAlertNotifications::class, function ($job) use ($alertId) {
            $reflection = new \ReflectionProperty($job, 'emergencyAlertId');
            $reflection->setAccessible(true);

            return $reflection->getValue($job) === $alertId;
        });
    }

    public function test_store_still_returns_201_when_every_delivery_channel_fails_synchronously(): void
    {
        // RIVA-102 regression: with QUEUE_CONNECTION=sync (as in this test env,
        // and as still shipped in the current .env), dispatch() runs the job
        // inline. If every delivery channel fails, the job's handle() throws —
        // that must never turn an already-successful "alert saved, care team
        // notified in-app" outcome into an HTTP 500. See
        // EmergencyAlertController::store()'s try/catch around dispatch().
        config([
            'services.emergency.admin_email' => '',
            'services.twilio.sid' => null,
            'services.twilio.token' => null,
            'services.twilio.from' => null,
        ]);

        [$patientUser] = $this->makePatient();
        Sanctum::actingAs($patientUser);

        $response = $this->postJson('/api/emergency-alerts', [
            'severity' => 'critical',
            'message' => 'Chest pain',
            'pain_level' => 9,
        ]);

        $response->assertCreated();
        $response->assertJson(['success' => true]);
        $this->assertSame(1, EmergencyAlert::count());
    }

    public function test_job_declares_three_tries_with_increasing_backoff(): void
    {
        $job = new SendEmergencyAlertNotifications(1);

        $this->assertSame(3, $job->tries);
        $this->assertSame([30, 120, 300], $job->backoff);
    }

    public function test_handle_succeeds_and_records_whatsapp_delivery_when_a_channel_works(): void
    {
        // RIVA-113: this test previously relied on whatever real Twilio/
        // emergency-admin config happened to be sitting in the local .env
        // to make a channel "work" — passed silently in this sandbox
        // (which has real values) but fails hard in any clean environment
        // (a fresh clone, CI) where .env.example ships
        // EMERGENCY_ADMIN_EMAIL/EMERGENCY_ADMIN_WHATSAPP/TWILIO_* empty on
        // purpose (correctly — a fresh clone should never inherit a real
        // person's contact details as a "default"). With every one of
        // those empty, config/services.php's own fallback defaults
        // ('or455510@gmail.com' / '+201012077945') are dead code that
        // never actually applies once the key is present-but-empty in
        // .env — env() returns the empty value, not the default — so
        // sendUrgentEmails() gets zero recipients and sendWhatsApp() skips
        // entirely, and the job throws "every channel failed" instead of
        // succeeding. Explicit fake config, matching how every other
        // Twilio-touching test in this suite already does it.
        config([
            'services.emergency.admin_email' => 'admin@riva.test',
            'services.emergency.admin_whatsapp' => '+15559998888',
            'services.twilio.sid' => 'test-sid',
            'services.twilio.token' => 'test-token',
            'services.twilio.from' => '+15550001111',
        ]);

        Http::fake([
            'api.twilio.com/*' => Http::response(['sid' => 'SM123'], 201),
        ]);

        [, $patient] = $this->makePatient();
        $alert = EmergencyAlert::create([
            'patient_id' => $patient->id,
            'severity' => 'critical',
            'message' => 'Chest pain',
        ]);

        (new SendEmergencyAlertNotifications($alert->id))->handle();

        $alert->refresh();
        $this->assertSame(1, $alert->delivery_attempts);
        $this->assertNotNull($alert->whatsapp_sent_at);
        $this->assertNull($alert->delivery_failed_at);
    }

    public function test_handle_throws_when_every_channel_fails_so_the_queue_retries(): void
    {
        // Force zero email recipients and a failing WhatsApp channel, so every
        // delivery channel genuinely fails on this attempt.
        config([
            'services.emergency.admin_email' => '',
            'services.twilio.sid' => null,
            'services.twilio.token' => null,
            'services.twilio.from' => null,
        ]);

        [, $patient] = $this->makePatient();
        $alert = EmergencyAlert::create([
            'patient_id' => $patient->id,
            'severity' => 'critical',
            'message' => 'Chest pain',
        ]);

        $this->expectException(\RuntimeException::class);

        (new SendEmergencyAlertNotifications($alert->id))->handle();
    }

    public function test_handle_still_succeeds_via_care_team_email_when_admin_email_is_unset(): void
    {
        // Even with no configured admin email, a caregiver with a real email address
        // is a valid delivery channel — confirms recipients aren't hardcoded to the
        // admin address alone.
        config(['services.emergency.admin_email' => '', 'services.twilio.sid' => null]);

        [, $patient] = $this->makePatient();
        $caregiverUser = User::create([
            'first_name' => 'Care', 'last_name' => uniqid('Giver'),
            'name' => 'Care Giver', 'email' => uniqid('caregiver').'@riva.test',
            'password' => 'password', 'role' => 'caregiver', 'is_active' => true,
            'profile_completed_at' => now(),
        ]);
        $caregiver = Caregiver::create(['user_id' => $caregiverUser->id]);
        PatientCaregiverRelationship::create([
            'caregiver_id' => $caregiver->id,
            'patient_id' => $patient->id,
            'status' => 'active',
        ]);

        $alert = EmergencyAlert::create([
            'patient_id' => $patient->id,
            'severity' => 'critical',
            'message' => 'Chest pain',
        ]);

        (new SendEmergencyAlertNotifications($alert->id))->handle();

        $this->assertSame(1, $alert->fresh()->delivery_attempts);
    }

    public function test_failed_marks_delivery_failed_and_notifies_every_admin(): void
    {
        [, $patient] = $this->makePatient();
        $admin1 = User::create([
            'first_name' => 'Admin', 'last_name' => 'One', 'name' => 'Admin One',
            'email' => uniqid('admin1').'@riva.test', 'password' => 'password',
            'role' => 'admin', 'is_active' => true, 'profile_completed_at' => now(),
        ]);
        $admin2 = User::create([
            'first_name' => 'Admin', 'last_name' => 'Two', 'name' => 'Admin Two',
            'email' => uniqid('admin2').'@riva.test', 'password' => 'password',
            'role' => 'admin', 'is_active' => true, 'profile_completed_at' => now(),
        ]);
        $alert = EmergencyAlert::create([
            'patient_id' => $patient->id,
            'severity' => 'critical',
            'message' => 'Chest pain',
        ]);

        (new SendEmergencyAlertNotifications($alert->id))->failed(new \RuntimeException('all channels exhausted'));

        $alert->refresh();
        $this->assertNotNull($alert->delivery_failed_at);

        foreach ([$admin1, $admin2] as $admin) {
            $this->assertDatabaseHas('notifications', [
                'notifiable_type' => User::class,
                'notifiable_id' => $admin->id,
                'type' => 'emergency_alert_delivery_failed',
            ]);
        }
    }

    public function test_failed_is_a_no_op_when_the_alert_no_longer_exists(): void
    {
        // Must not throw even if the alert was deleted between dispatch and the
        // final failed retry.
        (new SendEmergencyAlertNotifications(999999))->failed(new \RuntimeException('x'));
        $this->assertTrue(true);
    }

    private function makePatient(): array
    {
        $user = User::create([
            'first_name' => 'Patient', 'last_name' => uniqid('Emergency'),
            'name' => 'Patient Emergency', 'email' => uniqid('patient').'@riva.test',
            'password' => 'password', 'role' => 'patient', 'is_active' => true,
            'profile_completed_at' => now(),
        ]);

        return [$user, Patient::create(['user_id' => $user->id])];
    }
}
