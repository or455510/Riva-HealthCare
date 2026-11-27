<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Tests\TestCase;

/**
 * SECURITY (RIVA-119, P0 — account takeover) regression coverage for
 * AuthController::googleCallback(). Before this fix, googleCallback() used
 * User::firstOrCreate() keyed only on email. Since register()/login() in
 * this app never require email verification, that allowed a real account
 * takeover: an attacker registers victim@real-email.com with a password
 * only the attacker knows, then when the real victim later signs in with
 * their genuine Google account at that same address, googleCallback()
 * silently issues THEM a token for the attacker's pre-existing account —
 * which the attacker can still log into with their own password. Fixed by
 * refusing to auto-link a pre-existing, not-yet-Google-linked account.
 */
class GoogleOAuthAccountTakeoverTest extends TestCase
{
    use RefreshDatabase;

    public function test_google_login_is_rejected_when_the_email_already_has_a_password_only_account(): void
    {
        $victim = User::create([
            'first_name' => 'Attacker', 'last_name' => 'Planted', 'name' => 'Attacker Planted',
            'email' => 'victim@real-email.test', 'password' => 'attacker-controlled-password',
            'role' => 'patient', 'is_active' => true, 'profile_completed_at' => now(),
            // google_id intentionally left unset — this row was created via
            // normal password registration, not a prior Google login.
        ]);

        $this->mockGoogleUser('google-victim-id', 'Real Victim', 'victim@real-email.test');

        $response = $this->get('/api/auth/google/callback');

        $response->assertRedirect();
        $location = $response->headers->get('Location');
        $this->assertStringContainsString('error=account_exists', $location);

        // The attacker's account must be completely untouched — no google_id
        // grafted onto it, and critically, no new Sanctum token minted that
        // the real victim could have walked away with.
        $victim->refresh();
        $this->assertNull($victim->google_id);
        $this->assertSame(0, $victim->tokens()->count());
    }

    public function test_google_login_creates_a_brand_new_account_when_no_email_collision_exists(): void
    {
        $this->assertDatabaseMissing('users', ['email' => 'brand-new-google-user@real-email.test']);

        $this->mockGoogleUser('google-new-id', 'New Person', 'brand-new-google-user@real-email.test');

        $response = $this->get('/api/auth/google/callback');

        $response->assertRedirect();
        $location = $response->headers->get('Location');
        $this->assertStringContainsString('token=', $location);
        $this->assertStringNotContainsString('error=', $location);

        $created = User::where('email', 'brand-new-google-user@real-email.test')->first();
        $this->assertNotNull($created);
        $this->assertSame('google-new-id', $created->google_id);
    }

    public function test_a_returning_google_user_already_linked_can_log_in_again(): void
    {
        $returning = User::create([
            'first_name' => 'Returning', 'last_name' => 'Googler', 'name' => 'Returning Googler',
            'email' => 'returning@real-email.test', 'password' => 'irrelevant',
            'role' => 'patient', 'is_active' => true, 'profile_completed_at' => now(),
            'google_id' => 'google-returning-id',
        ]);

        $this->mockGoogleUser('google-returning-id', 'Returning Googler', 'returning@real-email.test');

        $response = $this->get('/api/auth/google/callback');

        $response->assertRedirect();
        $location = $response->headers->get('Location');
        $this->assertStringContainsString('token=', $location);
        $this->assertStringNotContainsString('error=', $location);

        $this->assertSame(1, User::where('email', 'returning@real-email.test')->count());
        $this->assertSame($returning->id, $returning->fresh()->id);
    }

    private function mockGoogleUser(string $id, string $name, string $email): void
    {
        $socialiteUser = (new SocialiteUser)->map([
            'id' => $id,
            'name' => $name,
            'email' => $email,
            'avatar' => 'https://example.test/avatar.png',
        ]);

        $provider = \Mockery::mock(Provider::class);
        $provider->shouldReceive('stateless')->andReturnSelf();
        $provider->shouldReceive('user')->andReturn($socialiteUser);

        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);
    }
}
