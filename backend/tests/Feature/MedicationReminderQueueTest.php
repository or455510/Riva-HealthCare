<?php

namespace Tests\Feature;

use App\Jobs\SendMedicationReminder;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * COST / SECURITY (RIVA-102) regression coverage: medication reminder delivery
 * used to happen synchronously inline in the request, to a hardcoded WhatsApp
 * number regardless of who triggered it. See SendMedicationReminder's docblock
 * and the cost/scalability audit §22 P0-5.
 */
class MedicationReminderQueueTest extends TestCase
{
    use RefreshDatabase;

    private function makePatientUser(string $phone = '+201234567890'): User
    {
        $user = User::create([
            'first_name' => 'Reminder', 'last_name' => uniqid('Test'),
            'name' => 'Reminder Test', 'email' => uniqid('reminder').'@riva.test',
            'phone' => $phone,
            'password' => 'password', 'role' => 'patient', 'is_active' => true,
            'profile_completed_at' => now(),
        ]);
        Patient::create(['user_id' => $user->id]);

        return $user;
    }

    public function test_send_dispatches_a_job_instead_of_sending_synchronously(): void
    {
        Bus::fake();

        $user = $this->makePatientUser();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/medication-reminder/send', [
            'medication_name' => 'Metformin',
            'schedule_time' => '8:00 AM',
        ]);

        $response->assertOk();
        $response->assertJson(['success' => true]);

        Bus::assertDispatched(SendMedicationReminder::class, function (SendMedicationReminder $job) use ($user) {
            $ref = new \ReflectionClass($job);
            $userId = $ref->getProperty('userId');
            $userId->setAccessible(true);
            $medicationName = $ref->getProperty('medicationName');
            $medicationName->setAccessible(true);

            return $userId->getValue($job) === $user->id
                && $medicationName->getValue($job) === 'Metformin';
        });
    }

    public function test_job_sends_whatsapp_to_the_triggering_users_own_phone_not_a_hardcoded_number(): void
    {
        Config::set('services.twilio.sid', 'test-sid');
        Config::set('services.twilio.token', 'test-token');
        Config::set('services.twilio.from', '+15550001111');

        $user = $this->makePatientUser('+201111111111');

        Http::fake([
            'api.twilio.com/*' => Http::response(['sid' => 'SM123'], 201),
        ]);

        (new SendMedicationReminder($user->id, 'Metformin', '8:00 AM'))->handle();

        Http::assertSent(function ($request) {
            $body = $request->data();

            return $request->url() === 'https://api.twilio.com/2010-04-01/Accounts/test-sid/Messages.json'
                && ($body['To'] ?? null) === 'whatsapp:+201111111111'
                && ($body['To'] ?? null) !== 'whatsapp:+201060465845';
        });
    }

    public function test_job_skips_whatsapp_gracefully_when_user_has_no_phone(): void
    {
        Config::set('services.twilio.sid', 'test-sid');
        Config::set('services.twilio.token', 'test-token');
        Config::set('services.twilio.from', '+15550001111');

        $user = $this->makePatientUser(phone: '');

        Http::fake();

        (new SendMedicationReminder($user->id, 'Metformin', '8:00 AM'))->handle();

        Http::assertNothingSent();
    }
}
