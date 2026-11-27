<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * RIVA notification-noise audit (product-owner feedback, 2026-08-25): "the
 * site sends a notification for EVERY single login instead of only
 * important events." AuthController::login() previously fired a generic
 * 'login_alert' notification on every successful login, with no real
 * detail. It now only alerts the first time a given account logs in from a
 * given IP (the same "new device/location" pattern real sites use), and the
 * message carries real detail (device/browser, IP, timestamp) instead of a
 * one-line generic string.
 */
class LoginNotificationNoiseTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_login_from_a_new_ip_creates_a_detailed_alert(): void
    {
        $user = User::factory()->create([
            'first_name' => 'Noise', 'last_name' => 'Test', 'phone' => '01000000020',
            'email' => 'noise-test@riva.test', 'role' => 'patient', 'is_active' => true,
            'password' => Hash::make('correct-password'),
        ]);

        $this->postJson('/api/auth/login', [
            'email' => 'noise-test@riva.test',
            'password' => 'correct-password',
        ])->assertOk();

        $alert = $user->notifications()->where('type', 'login_alert')->first();

        $this->assertNotNull($alert);
        $this->assertStringContainsString('127.0.0.1', $alert->message);
        $this->assertNotSame('A new login was detected on your account.', $alert->message);
    }

    public function test_second_login_from_the_same_ip_does_not_repeat_the_alert(): void
    {
        $user = User::factory()->create([
            'first_name' => 'Repeat', 'last_name' => 'Test', 'phone' => '01000000021',
            'email' => 'repeat-login@riva.test', 'role' => 'patient', 'is_active' => true,
            'password' => Hash::make('correct-password'),
        ]);

        $this->postJson('/api/auth/login', [
            'email' => 'repeat-login@riva.test',
            'password' => 'correct-password',
        ])->assertOk();

        RateLimiter::clear('login:repeat-login@riva.test:127.0.0.1');

        $this->postJson('/api/auth/login', [
            'email' => 'repeat-login@riva.test',
            'password' => 'correct-password',
        ])->assertOk();

        // Two successful logins from the same IP — only the first is
        // "new," so exactly one alert should exist, not two.
        $this->assertSame(1, $user->notifications()->where('type', 'login_alert')->count());
    }
}
