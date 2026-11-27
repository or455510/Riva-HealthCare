<?php

namespace Tests\Feature;

use App\Models\Doctor;
use App\Models\DoctorVerification;
use App\Models\Message;
use App\Models\Patient;
use App\Models\PatientDoctorRelationship;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * File-serving architecture (principal-engineering directive): end-to-end coverage
 * for the signed-URL mechanism that replaced raw Storage::disk('public')->url() /
 * asset('storage/...') links for chat attachments and doctor-verification documents.
 * See StorageFileController's class docblock and Message::getFileUrlAttribute() /
 * DoctorVerification::publicUrl() for the design.
 */
class SignedFileUrlTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    public function test_chat_upload_response_returns_a_signed_url_not_a_raw_storage_url(): void
    {
        // RIVA-117: upload() now requires the same canChatWith() relationship
        // check as store() — an unrelated patient/doctor pair would be 403'd
        // before ever reaching the signed-URL logic this test actually exists
        // to cover, so this needs a real, related pair like the other tests
        // in this file already use.
        [$sender, $receiver] = $this->makeRelatedPatientAndDoctor();
        Sanctum::actingAs($sender);

        $response = $this->postJson('/api/messages/upload', [
            'file' => UploadedFile::fake()->image('photo.jpg'),
            'receiver_id' => $receiver->id,
        ])->assertCreated();

        $fileUrl = $response->json('data.file_url');
        $this->assertNotNull($fileUrl);
        $this->assertStringContainsString('/api/files/signed/', $fileUrl);
        $this->assertStringContainsString('signature=', $fileUrl);
        // The old bypass format must be gone.
        $this->assertStringNotContainsString('/storage/messages/', $fileUrl);
    }

    public function test_message_list_signed_url_is_usable_by_sender_and_receiver_but_not_a_stranger(): void
    {
        [$sender, $receiver] = $this->makeRelatedPatientAndDoctor();
        $stranger = $this->makeUser('patient');

        $path = 'messages/'.UploadedFile::fake()->image('photo.jpg')->hashName();
        Storage::disk('public')->put($path, 'fake-bytes');

        Message::create([
            'sender_id' => $sender->id,
            'receiver_id' => $receiver->id,
            'body' => '',
            'file_url' => $path,
            'file_type' => 'image',
            'file_name' => 'photo.jpg',
        ]);

        // Sender fetches their own conversation -> gets a signed URL scoped to
        // themselves -> that URL actually works, unauthenticated (as a browser
        // <img src> would fetch it), because the signature IS the credential.
        Sanctum::actingAs($sender);
        $senderUrl = $this->getJson("/api/messages/{$receiver->id}")
            ->assertOk()
            ->json('data.0.file_url');
        $this->assertNotNull($senderUrl);
        $this->getJson($senderUrl)->assertOk();

        // Receiver, viewing the same conversation, gets their OWN signed URL
        // (different uid baked in) — also works.
        Sanctum::actingAs($receiver);
        $receiverUrl = $this->getJson("/api/messages/{$sender->id}")
            ->assertOk()
            ->json('data.0.file_url');
        $this->assertNotNull($receiverUrl);
        $this->getJson($receiverUrl)->assertOk();

        // A stranger cannot even mint a URL for this message (canChatWith() blocks
        // them at /api/messages/{user}), and if they somehow obtained one of the
        // above URLs and simply reused it, it still 200s only because they'd be
        // reusing someone else's still-valid token, not because they broke the
        // authorization — so instead prove the actual security property: a
        // manually-assembled URL for the same path but the stranger's own uid,
        // WITHOUT a valid signature, is rejected.
        Sanctum::actingAs($stranger);
        $this->getJson("/api/messages/{$sender->id}")->assertForbidden();
        $this->getJson('/api/files/signed/'.$path.'?uid='.$stranger->id)->assertForbidden();
    }

    public function test_tampering_the_uid_query_param_invalidates_the_signature(): void
    {
        [$sender, $receiver] = $this->makeRelatedPatientAndDoctor();
        $stranger = $this->makeUser('patient');

        $path = 'messages/'.UploadedFile::fake()->image('photo.jpg')->hashName();
        Storage::disk('public')->put($path, 'fake-bytes');

        Message::create([
            'sender_id' => $sender->id,
            'receiver_id' => $receiver->id,
            'body' => '',
            'file_url' => $path,
            'file_type' => 'image',
            'file_name' => 'photo.jpg',
        ]);

        Sanctum::actingAs($sender);
        $signedUrl = $this->getJson("/api/messages/{$receiver->id}")
            ->assertOk()
            ->json('data.0.file_url');

        // Swap ?uid=<sender> for ?uid=<stranger> in the already-signed URL without
        // recomputing the signature — the HMAC no longer matches the query string,
        // so this must be rejected even though the stranger has never authenticated
        // as anyone and the path/file genuinely exists.
        $tampered = preg_replace('/uid=\d+/', 'uid='.$stranger->id, $signedUrl);
        $this->assertNotEquals($signedUrl, $tampered);
        $this->getJson($tampered)->assertForbidden();
    }

    public function test_expired_signed_url_is_rejected(): void
    {
        [$sender, $receiver] = $this->makeRelatedPatientAndDoctor();

        $path = 'messages/'.UploadedFile::fake()->image('photo.jpg')->hashName();
        Storage::disk('public')->put($path, 'fake-bytes');

        Message::create([
            'sender_id' => $sender->id,
            'receiver_id' => $receiver->id,
            'body' => '',
            'file_url' => $path,
            'file_type' => 'image',
            'file_name' => 'photo.jpg',
        ]);

        Sanctum::actingAs($sender);
        $signedUrl = $this->getJson("/api/messages/{$receiver->id}")
            ->assertOk()
            ->json('data.0.file_url');

        $this->getJson($signedUrl)->assertOk();

        $this->travel(31)->minutes();
        $this->getJson($signedUrl)->assertForbidden();
    }

    public function test_doctor_verification_signed_url_scoped_to_owning_doctor_and_admin(): void
    {
        [$ownerUser, $ownerDoctor] = $this->makeDoctor();
        [$otherUser] = $this->makeDoctor();
        $admin = User::create([
            'first_name' => 'Admin', 'last_name' => 'User', 'name' => 'Admin User',
            'email' => uniqid('admin').'@riva.test', 'password' => 'password',
            'role' => 'admin', 'is_active' => true, 'profile_completed_at' => now(),
        ]);

        $path = 'doctor-verifications/'.UploadedFile::fake()->image('license.jpg')->hashName();
        Storage::disk('public')->put($path, 'fake-bytes');

        $verification = DoctorVerification::create([
            'doctor_id' => $ownerDoctor->id,
            'national_id_file' => 'doctor-verifications/placeholder-national-id.jpg',
            'medical_license_file' => $path,
            'syndicate_card_file' => 'doctor-verifications/placeholder-syndicate-card.jpg',
            'status' => 'pending',
        ]);

        // Owning doctor: fetch their own status, get a working signed URL.
        Sanctum::actingAs($ownerUser);
        $ownerUrl = $this->getJson('/api/doctor/verification/status')
            ->assertOk()
            ->json('data.verification.medical_license_file_url');
        $this->assertNotNull($ownerUrl);
        $this->assertStringContainsString('/api/files/signed/', $ownerUrl);
        $this->getJson($ownerUrl)->assertOk();

        // A different, unrelated doctor manually hitting the signed endpoint with
        // their own uid and the real path (no valid signature for that pairing)
        // is rejected.
        Sanctum::actingAs($otherUser);
        $this->getJson('/api/files/signed/'.$path.'?uid='.$otherUser->id)->assertForbidden();

        // Admin: reviewing the same record gets their own scoped, working URL.
        Sanctum::actingAs($admin);
        $adminUrl = $this->getJson("/api/admin/doctor-verifications/{$verification->id}")
            ->assertOk()
            ->json('data.medical_license_file_url');
        $this->assertNotNull($adminUrl);
        $this->getJson($adminUrl)->assertOk();
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

    /**
     * ChatController::canChatWith() requires an active/accepted
     * PatientDoctorRelationship, not just two authenticated users — build one so
     * these tests reach the signed-URL logic under test rather than being turned
     * away at the chat-authorization layer.
     *
     * @return array{0: User, 1: User} [$patientUser, $doctorUser]
     */
    private function makeRelatedPatientAndDoctor(): array
    {
        $patientUser = $this->makeUser('patient');
        $patient = Patient::create(['user_id' => $patientUser->id]);

        [$doctorUser, $doctor] = $this->makeDoctor();

        // 'active' rather than 'accepted': on the sqlite test connection the
        // status column's CHECK constraint still reflects the original
        // create-table migration's enum (pending/active/rejected/ended) — the
        // 'accepted' value added by a later migration is applied via a MySQL-only
        // `ALTER TABLE ... MODIFY` statement that has no sqlite equivalent. Both
        // values are accepted by ChatController::canChatWith()'s
        // whereIn('status', ['active', 'accepted']) check, so 'active' exercises
        // the same authorization path without hitting that pre-existing,
        // unrelated migration/sqlite gap.
        PatientDoctorRelationship::create([
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
            'status' => 'active',
            'requested_at' => now(),
            'responded_at' => now(),
        ]);

        return [$patientUser, $doctorUser];
    }
}
