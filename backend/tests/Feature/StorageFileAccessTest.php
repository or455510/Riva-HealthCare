<?php

namespace Tests\Feature;

use App\Models\Doctor;
use App\Models\DoctorVerification;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StorageFileAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/files/profile_images/anything.png')->assertUnauthorized();
    }

    public function test_doctor_can_view_own_verification_file_but_other_doctor_cannot(): void
    {
        [$ownerUser, $ownerDoctor] = $this->makeDoctor();
        [$otherUser] = $this->makeDoctor();

        $path = 'doctor-verifications/'.UploadedFile::fake()->image('license.jpg')->hashName();
        Storage::disk('public')->put($path, 'fake-bytes');

        // DoctorVerificationController::submit() requires national_id_file,
        // medical_license_file, and syndicate_card_file together (all three are
        // `required` in its validation, and the migration makes all three NOT NULL
        // columns) — a real verification row is never created with only one file.
        // The file under test ($path) is the one actually being requested/asserted
        // against; the other two just need to be valid non-null paths.
        DoctorVerification::create([
            'doctor_id' => $ownerDoctor->id,
            'national_id_file' => 'doctor-verifications/placeholder-national-id.jpg',
            'medical_license_file' => $path,
            'syndicate_card_file' => 'doctor-verifications/placeholder-syndicate-card.jpg',
            'status' => 'pending',
        ]);

        Sanctum::actingAs($ownerUser);
        $this->get('/api/files/'.$path)->assertOk();

        Sanctum::actingAs($otherUser);
        $this->get('/api/files/'.$path)->assertForbidden();
    }

    public function test_admin_can_view_any_doctor_verification_file(): void
    {
        [, $doctor] = $this->makeDoctor();
        $admin = User::create([
            'first_name' => 'Admin', 'last_name' => 'User', 'name' => 'Admin User',
            'email' => uniqid('admin').'@riva.test', 'password' => 'password',
            'role' => 'admin', 'is_active' => true, 'profile_completed_at' => now(),
        ]);

        $path = 'doctor-verifications/'.UploadedFile::fake()->image('license.jpg')->hashName();
        Storage::disk('public')->put($path, 'fake-bytes');

        // See the sibling test above for why all three required file fields are
        // supplied here, not just the one under test.
        DoctorVerification::create([
            'doctor_id' => $doctor->id,
            'national_id_file' => 'doctor-verifications/placeholder-national-id.jpg',
            'medical_license_file' => $path,
            'syndicate_card_file' => 'doctor-verifications/placeholder-syndicate-card.jpg',
            'status' => 'pending',
        ]);

        Sanctum::actingAs($admin);
        $this->get('/api/files/'.$path)->assertOk();
    }

    public function test_only_sender_or_receiver_can_view_a_chat_attachment(): void
    {
        $sender = $this->makeUser('patient');
        $receiver = $this->makeUser('doctor');
        $stranger = $this->makeUser('patient');

        $path = 'messages/'.UploadedFile::fake()->image('photo.jpg')->hashName();
        Storage::disk('public')->put($path, 'fake-bytes');

        Message::create([
            'sender_id' => $sender->id,
            'receiver_id' => $receiver->id,
            'body' => '',
            'file_url' => asset('storage/'.$path),
            'file_type' => 'image',
            'file_name' => 'photo.jpg',
        ]);

        Sanctum::actingAs($sender);
        $this->get('/api/files/'.$path)->assertOk();

        Sanctum::actingAs($receiver);
        $this->get('/api/files/'.$path)->assertOk();

        Sanctum::actingAs($stranger);
        $this->get('/api/files/'.$path)->assertForbidden();
    }

    public function test_unknown_path_prefix_is_denied_by_default(): void
    {
        $user = $this->makeUser('patient');
        $path = 'some-unexpected-folder/file.txt';
        Storage::disk('public')->put($path, 'fake-bytes');

        Sanctum::actingAs($user);
        $this->get('/api/files/'.$path)->assertForbidden();
    }

    public function test_path_traversal_outside_public_disk_is_rejected(): void
    {
        $user = $this->makeUser('patient');
        Sanctum::actingAs($user);

        $this->get('/api/files/..%2F..%2F..%2F.env')->assertNotFound();
        $this->get('/api/files/../../../.env')->assertNotFound();
    }

    private function makeUser(string $role): User
    {
        return User::create([
            'first_name' => ucfirst($role), 'last_name' => uniqid(),
            'name' => ucfirst($role).' User', 'email' => uniqid($role).'@riva.test',
            'password' => 'password', 'role' => $role, 'is_active' => true,
            'profile_completed_at' => now(),
        ]);
    }

    private function makeDoctor(): array
    {
        $user = $this->makeUser('doctor');

        return [$user, Doctor::create(['user_id' => $user->id])];
    }
}
