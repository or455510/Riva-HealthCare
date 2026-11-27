<?php

namespace Tests\Unit;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * RIVA image-bug audit (product-owner request, recorded 2026-08-25):
 * User::profile_image_url must never be null — every frontend screen
 * already falls back to a generated avatar client-side, but at least one
 * screen (the doctor dashboard's follow-requests widget) doesn't, and
 * rendered a broken image for a brand-new placeholder account with no
 * uploaded photo. Proves the three real states: uploaded photo wins,
 * Google avatar is used when there's no upload, and a generated
 * ui-avatars.com initials URL is the honest last resort — never null.
 */
class UserAvatarFallbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_uploaded_photo_is_used_when_present(): void
    {
        $user = User::create([
            'first_name' => 'Ava', 'last_name' => 'Tar',
            'name' => 'Ava Tar', 'email' => uniqid('avatar').'@riva.test',
            'password' => 'password', 'role' => 'patient', 'is_active' => true,
            'profile_image' => 'profile_images/abc123.jpg',
        ]);

        $this->assertStringContainsString('profile_images/abc123.jpg', $user->profile_image_url);
    }

    public function test_google_avatar_is_used_when_no_uploaded_photo(): void
    {
        $user = User::create([
            'first_name' => 'Ava', 'last_name' => 'Tar',
            'name' => 'Ava Tar', 'email' => uniqid('avatar').'@riva.test',
            'password' => 'password', 'role' => 'patient', 'is_active' => true,
            'google_avatar' => 'https://lh3.googleusercontent.com/fake-avatar.jpg',
        ]);

        $this->assertSame('https://lh3.googleusercontent.com/fake-avatar.jpg', $user->profile_image_url);
    }

    public function test_a_generated_initials_avatar_is_returned_instead_of_null_when_neither_exists(): void
    {
        $user = User::create([
            'first_name' => 'Invited', 'last_name' => 'Member',
            'name' => 'Invited Member', 'email' => uniqid('avatar').'@riva.test',
            'password' => 'password', 'role' => 'patient', 'is_active' => true,
        ]);

        $this->assertNotNull($user->profile_image_url);
        $this->assertStringContainsString('ui-avatars.com', $user->profile_image_url);
        $this->assertStringContainsString('Invited', $user->profile_image_url);
    }
}
