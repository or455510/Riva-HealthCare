<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * SECURITY (RIVA-118, P0) regression coverage for AuthController::
 * forgotPassword(). Before this fix, a mail-send failure returned a
 * distinct 503 response, but ONLY for an email that actually belongs to a
 * real account — a nonexistent email always got the generic "if the email
 * exists" success message regardless of mail outcome (since the mail-send
 * code never ran for it at all). That let an attacker distinguish "this
 * email exists" from "this email doesn't exist" purely by response code,
 * with no need to ever receive an email — a user-enumeration oracle on a
 * password-reset endpoint.
 */
class PasswordResetEnumerationTest extends TestCase
{
    use RefreshDatabase;

    public function test_response_is_identical_for_an_existing_and_a_nonexistent_email_when_mail_fails(): void
    {
        Mail::shouldReceive('raw')->andThrow(new \RuntimeException('mail transport down'));

        $existingUser = User::create([
            'first_name' => 'Reset', 'last_name' => 'User', 'name' => 'Reset User',
            'email' => 'existing-reset-user@riva.test', 'password' => 'password',
            'role' => 'patient', 'is_active' => true, 'profile_completed_at' => now(),
        ]);

        $existingResponse = $this->postJson('/api/auth/forgot-password', [
            'email' => $existingUser->email,
        ]);
        $nonexistentResponse = $this->postJson('/api/auth/forgot-password', [
            'email' => 'no-such-account-at-all@riva.test',
        ]);

        $existingResponse->assertOk();
        $nonexistentResponse->assertOk();
        $this->assertSame($existingResponse->status(), $nonexistentResponse->status());
        $this->assertSame($existingResponse->json('message'), $nonexistentResponse->json('message'));
    }

    public function test_a_reset_code_row_is_still_written_even_though_mail_sending_failed(): void
    {
        Mail::shouldReceive('raw')->andThrow(new \RuntimeException('mail transport down'));

        $user = User::create([
            'first_name' => 'Reset', 'last_name' => 'User2', 'name' => 'Reset User2',
            'email' => 'reset-row-check@riva.test', 'password' => 'password',
            'role' => 'patient', 'is_active' => true, 'profile_completed_at' => now(),
        ]);

        $this->postJson('/api/auth/forgot-password', ['email' => $user->email])->assertOk();

        $this->assertDatabaseHas('password_reset_tokens', ['email' => $user->email]);
    }
}
