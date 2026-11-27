<?php

namespace Tests\Feature;

use App\Models\Patient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * COST / SECURITY (RIVA-101) regression coverage: the API previously had no
 * rate limiting at all outside AuthController's hand-rolled login/forgot
 * -password limiters — see docs/RIVA_COST_MODEL.md and
 * backend/docs/engineering/RIVA_COST_AND_SCALABILITY_AUDIT.md §22 P0-1.
 * These tests confirm the new named limiters (AppServiceProvider::
 * registerRateLimiters()) actually apply where routes/api.php wires them.
 *
 * Http::fake() is used throughout to guarantee zero real outbound calls to
 * Gemini/Twilio during these tests — both are live-configured in the local
 * .env, and the throttle middleware runs before the controller reaches
 * either of them regardless, so faking is purely a safety measure, not a
 * requirement for the throttle behavior itself.
 */
class ApiRateLimitTest extends TestCase
{
    use RefreshDatabase;

    private function makePatientUser(): User
    {
        $user = User::create([
            'first_name' => 'Rate', 'last_name' => uniqid('Limit'),
            'name' => 'Rate Limit', 'email' => uniqid('ratelimit').'@riva.test',
            'password' => 'password', 'role' => 'patient', 'is_active' => true,
            'profile_completed_at' => now(),
        ]);
        Patient::create(['user_id' => $user->id]);

        return $user;
    }

    public function test_ai_assistant_endpoint_is_throttled_after_six_requests_per_minute(): void
    {
        Http::fake(); // never actually reach Gemini

        Sanctum::actingAs($this->makePatientUser());

        $payload = ['message' => 'hello', 'personaId' => 'primary-care'];

        for ($i = 0; $i < 6; $i++) {
            $response = $this->postJson('/api/ai-assistant/message', $payload);
            $this->assertNotEquals(429, $response->getStatusCode(), "Request {$i} should not be throttled yet.");
        }

        $this->postJson('/api/ai-assistant/message', $payload)->assertStatus(429);
    }

    public function test_contact_message_endpoint_is_throttled_after_twenty_requests_per_minute(): void
    {
        Http::fake(); // never actually reach Twilio

        $payload = [
            'full_name' => 'Rate Limit Test',
            'email' => 'ratelimit@riva.test',
            'subject' => 'Testing',
            'message' => 'Testing the contact-message throttle.',
        ];

        for ($i = 0; $i < 20; $i++) {
            $response = $this->postJson('/api/contact-message', $payload);
            $this->assertNotEquals(429, $response->getStatusCode(), "Request {$i} should not be throttled yet.");
        }

        $this->postJson('/api/contact-message', $payload)->assertStatus(429);
    }

    public function test_general_api_throttle_does_not_interfere_with_normal_usage(): void
    {
        // A handful of ordinary public requests must never be throttled —
        // the 'api' default limiter is 120/min, deliberately generous.
        for ($i = 0; $i < 10; $i++) {
            $this->getJson('/api/doctors')->assertStatus(200);
        }
    }

    public function test_register_endpoint_is_throttled_after_ten_requests_per_minute(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $response = $this->postJson('/api/auth/register', [
                'first_name' => 'Rate',
                'last_name' => 'Limit',
                'email' => "ratelimit{$i}-".uniqid().'@riva.test',
                'password' => 'password',
                'password_confirmation' => 'password',
                'role' => 'patient',
            ]);
            $this->assertNotEquals(429, $response->getStatusCode(), "Request {$i} should not be throttled yet.");
        }

        $response = $this->postJson('/api/auth/register', [
            'first_name' => 'Rate',
            'last_name' => 'Limit',
            'email' => 'ratelimit-overflow-'.uniqid().'@riva.test',
            'password' => 'password',
            'password_confirmation' => 'password',
            'role' => 'patient',
        ]);
        $response->assertStatus(429);
    }
}
