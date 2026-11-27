<?php

namespace Tests\Feature;

use App\Models\Clinic;
use App\Models\ClinicUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * RIVA-143 (product decision, recorded 2026-08-25, confirmed by the product
 * owner): a clinic owner/admin can now invite someone by email who has NO
 * existing Riva account — the reported bug was that
 * ClinicMembershipController::store() required `exists:users,email`, making
 * it impossible to invite a brand-new secretary. This suite proves the new
 * invite-link flow end to end: invite creates a real, claimable account with
 * an expiring single-use token; the invitee accepts it, sets their own
 * password, and becomes an active member; the token cannot be reused; and
 * inviting an email that DOES already have an account is unchanged
 * (existing notify-based flow, no token/link).
 */
class ClinicStaffInviteLinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_invite_an_email_with_no_existing_account(): void
    {
        [$owner, $clinic] = $this->makeClinicWithOwner();

        Sanctum::actingAs($owner);
        $response = $this->postJson('/api/clinic/members', [
            'email' => 'newsecretary@riva.test',
            'role' => 'staff',
        ])->assertCreated();

        $response->assertJsonPath('data.email', 'newsecretary@riva.test')
            ->assertJsonPath('data.status', 'invited')
            ->assertJsonPath('data.is_new_account', true);

        $inviteLink = $response->json('data.invite_link');
        $this->assertNotNull($inviteLink, 'A brand-new invitee must get a real, usable invite link back.');
        $this->assertStringContainsString('/accept-clinic-invite/', $inviteLink);

        $this->assertDatabaseHas('users', ['email' => 'newsecretary@riva.test', 'role' => 'patient']);
        $this->assertDatabaseHas('clinic_user', [
            'clinic_id' => $clinic->id,
            'role' => 'staff',
            'status' => 'invited',
        ]);
    }

    public function test_invited_email_never_leaks_the_raw_token_from_other_endpoints(): void
    {
        [$owner, $clinic] = $this->makeClinicWithOwner();

        Sanctum::actingAs($owner);
        $this->postJson('/api/clinic/members', ['email' => 'hidden@riva.test', 'role' => 'staff'])->assertCreated();

        $listResponse = $this->getJson('/api/clinic/members')->assertOk();
        $rawJson = json_encode($listResponse->json());
        $this->assertStringNotContainsString('invite_token', $rawJson);
    }

    public function test_invitee_can_view_invite_details_without_authenticating(): void
    {
        [$owner, $clinic] = $this->makeClinicWithOwner();

        Sanctum::actingAs($owner);
        $inviteResponse = $this->postJson('/api/clinic/members', [
            'email' => 'viewer@riva.test',
            'role' => 'staff',
        ])->assertCreated();

        $token = $this->extractToken($inviteResponse->json('data.invite_link'));

        // Deliberately no Sanctum::actingAs() — this must be reachable
        // pre-login.
        $this->getJson("/api/clinic-invites/{$token}")
            ->assertOk()
            ->assertJsonPath('data.clinic_name', $clinic->name)
            ->assertJsonPath('data.role', 'staff')
            ->assertJsonPath('data.email', 'viewer@riva.test');
    }

    public function test_invitee_can_accept_the_invite_set_a_password_and_becomes_active(): void
    {
        [$owner, $clinic] = $this->makeClinicWithOwner();

        Sanctum::actingAs($owner);
        $inviteResponse = $this->postJson('/api/clinic/members', [
            'email' => 'accept@riva.test',
            'role' => 'staff',
        ])->assertCreated();
        $token = $this->extractToken($inviteResponse->json('data.invite_link'));

        $acceptResponse = $this->postJson("/api/clinic-invites/{$token}/accept", [
            'first_name' => 'New',
            'last_name' => 'Secretary',
            'password' => 'a-real-password',
            'password_confirmation' => 'a-real-password',
        ])->assertCreated();

        $acceptResponse->assertJsonPath('data.user.email', 'accept@riva.test')
            ->assertJsonPath('data.clinic_role', 'staff');
        $this->assertNotEmpty($acceptResponse->json('data.token'), 'Accepting an invite must sign the user straight in.');

        $membership = ClinicUser::where('clinic_id', $clinic->id)
            ->whereHas('user', fn ($q) => $q->where('email', 'accept@riva.test'))
            ->firstOrFail();
        $this->assertSame('active', $membership->status);
        $this->assertNotNull($membership->joined_at);
        $this->assertNull($membership->invite_token, 'The token must be consumed after acceptance.');

        $user = User::where('email', 'accept@riva.test')->firstOrFail();
        $this->assertSame('New', $user->first_name);
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('a-real-password', $user->password));
    }

    public function test_an_invite_token_cannot_be_used_twice(): void
    {
        [$owner, $clinic] = $this->makeClinicWithOwner();

        Sanctum::actingAs($owner);
        $inviteResponse = $this->postJson('/api/clinic/members', [
            'email' => 'oncetoken@riva.test',
            'role' => 'staff',
        ])->assertCreated();
        $token = $this->extractToken($inviteResponse->json('data.invite_link'));

        $payload = [
            'first_name' => 'Once',
            'last_name' => 'Only',
            'password' => 'a-real-password',
            'password_confirmation' => 'a-real-password',
        ];

        $this->postJson("/api/clinic-invites/{$token}/accept", $payload)->assertCreated();
        $this->postJson("/api/clinic-invites/{$token}/accept", $payload)->assertNotFound();
    }

    public function test_inviting_an_email_with_an_existing_account_is_unchanged_no_token_issued(): void
    {
        [$owner, $clinic] = $this->makeClinicWithOwner();
        $existingUser = $this->makeUser('existing@riva.test');

        Sanctum::actingAs($owner);
        $response = $this->postJson('/api/clinic/members', [
            'email' => 'existing@riva.test',
            'role' => 'staff',
        ])->assertCreated();

        $response->assertJsonPath('data.is_new_account', false)
            ->assertJsonPath('data.invite_link', null);

        $this->assertDatabaseHas('clinic_user', [
            'clinic_id' => $clinic->id,
            'user_id' => $existingUser->id,
            'status' => 'invited',
        ]);
    }

    public function test_non_manager_cannot_invite_anyone(): void
    {
        [, $clinic] = $this->makeClinicWithOwner();
        $staffUser = $this->makeUser('nomanage@riva.test');
        ClinicUser::create([
            'clinic_id' => $clinic->id, 'user_id' => $staffUser->id,
            'role' => ClinicUser::ROLE_STAFF, 'status' => 'active', 'joined_at' => now(),
        ]);

        Sanctum::actingAs($staffUser);
        $this->postJson('/api/clinic/members', [
            'email' => 'blocked@riva.test',
            'role' => 'staff',
        ])->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'blocked@riva.test']);
    }

    private function extractToken(?string $inviteLink): string
    {
        $this->assertNotNull($inviteLink);
        $parts = explode('/accept-clinic-invite/', $inviteLink);
        $this->assertCount(2, $parts);

        return $parts[1];
    }

    private function makeClinicWithOwner(): array
    {
        $owner = $this->makeUser('owner-'.uniqid().'@riva.test');
        $clinic = Clinic::create([
            'name' => 'Invite Test Clinic',
            'slug' => 'invite-clinic-'.uniqid(),
            'owner_user_id' => $owner->id,
            'status' => 'active',
        ]);
        ClinicUser::create([
            'clinic_id' => $clinic->id,
            'user_id' => $owner->id,
            'role' => ClinicUser::ROLE_OWNER,
            'status' => 'active',
            'joined_at' => now(),
        ]);

        return [$owner, $clinic];
    }

    private function makeUser(string $email): User
    {
        return User::create([
            'first_name' => 'Existing',
            'last_name' => 'User',
            'name' => 'Existing User',
            'email' => $email,
            'password' => 'password',
            'role' => 'doctor',
            'is_active' => true,
            'profile_completed_at' => now(),
        ]);
    }
}
