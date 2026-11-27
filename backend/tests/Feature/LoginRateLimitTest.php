<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * SECURITY (Phase 0 / S4) regression coverage: AuthController::login() throttles
 * repeated failed attempts per email+IP (see the docblock on login()). This test
 * closes the gap flagged in the Phase 0 verification report — the rate limiter was
 * shipped without a dedicated test asserting its actual runtime behavior.
 */
class LoginRateLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // The RateLimiter facade's underlying cache store persists across tests in
        // some configurations; clear explicitly so this test's throttle key starts
        // fresh regardless of test order.
        RateLimiter::clear('login:rate-limit-target@riva.test:127.0.0.1');
    }

    public function test_sixth_failed_login_attempt_is_throttled(): void
    {
        User::factory()->create([
            'first_name' => 'Target', 'last_name' => 'User', 'phone' => '01000000010',
            'email' => 'rate-limit-target@riva.test', 'role' => 'patient',
            'password' => Hash::make('correct-password'),
        ]);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/login', [
                'email' => 'rate-limit-target@riva.test',
                'password' => 'wrong-password',
            ])->assertStatus(422);
        }

        // The 6th attempt — even with the correct password — must be blocked by the
        // throttle before credentials are ever checked.
        $response = $this->postJson('/api/auth/login', [
            'email' => 'rate-limit-target@riva.test',
            'password' => 'correct-password',
        ]);

        $response->assertStatus(429);
        $this->assertStringContainsString('Too many login attempts', $response->json('message'));
    }

    public function test_successful_login_clears_the_throttle(): void
    {
        User::factory()->create([
            'first_name' => 'Clear', 'last_name' => 'User', 'phone' => '01000000011',
            'email' => 'rate-limit-clear@riva.test', 'role' => 'patient', 'is_active' => true,
            'password' => Hash::make('correct-password'),
        ]);

        RateLimiter::clear('login:rate-limit-clear@riva.test:127.0.0.1');

        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/auth/login', [
                'email' => 'rate-limit-clear@riva.test',
                'password' => 'wrong-password',
            ])->assertStatus(422);
        }

        // A correct login before the 5-attempt cap is reached must succeed and
        // reset the counter — it must not still count toward the throttle.
        $this->postJson('/api/auth/login', [
            'email' => 'rate-limit-clear@riva.test',
            'password' => 'correct-password',
        ])->assertOk();

        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/auth/login', [
                'email' => 'rate-limit-clear@riva.test',
                'password' => 'wrong-password',
            ])->assertStatus(422);
        }

        // Only 3 failures have accumulated since the clear — still under the cap of 5.
        $this->postJson('/api/auth/login', [
            'email' => 'rate-limit-clear@riva.test',
            'password' => 'correct-password',
        ])->assertOk();
    }

    public function test_throttle_is_scoped_per_email_not_shared_across_accounts(): void
    {
        User::factory()->create([
            'first_name' => 'Victim', 'last_name' => 'User', 'phone' => '01000000012',
            'email' => 'victim@riva.test', 'role' => 'patient', 'is_active' => true,
            'password' => Hash::make('victim-password'),
        ]);
        User::factory()->create([
            'first_name' => 'Bystander', 'last_name' => 'User', 'phone' => '01000000013',
            'email' => 'bystander@riva.test', 'role' => 'patient', 'is_active' => true,
            'password' => Hash::make('bystander-password'),
        ]);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/login', [
                'email' => 'victim@riva.test',
                'password' => 'wrong-password',
            ])->assertStatus(422);
        }

        // "victim" is now throttled, but a different account from the same IP must
        // not be collaterally locked out.
        $this->postJson('/api/auth/login', [
            'email' => 'bystander@riva.test',
            'password' => 'bystander-password',
        ])->assertOk();
    }
}
