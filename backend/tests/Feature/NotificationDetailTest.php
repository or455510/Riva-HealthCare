<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NotificationDetailTest extends TestCase
{
    use RefreshDatabase;

    public function test_notification_detail_returns_body_and_marks_read(): void
    {
        $user = User::factory()->create([
            'first_name' => 'Care',
            'last_name' => 'Giver',
            'phone' => '01000000001',
            'role' => 'caregiver',
        ]);
        $notification = Notification::create([
            'notifiable_type' => User::class,
            'notifiable_id' => $user->id,
            'type' => 'missed_medication',
            'title' => 'Missed Medication Alert',
            'message' => 'Riva Patient missed Donepezil scheduled at 10:30.',
            'category' => 'medication',
            'data' => ['related_type' => 'medication_log', 'related_id' => 15],
        ]);

        Sanctum::actingAs($user);

        $this->getJson("/api/notifications/{$notification->id}")
            ->assertOk()
            ->assertJsonPath('data.title', 'Missed Medication Alert')
            ->assertJsonPath('data.body', 'Riva Patient missed Donepezil scheduled at 10:30.')
            ->assertJsonPath('data.message', 'Riva Patient missed Donepezil scheduled at 10:30.')
            ->assertJsonPath('data.category', 'medication')
            ->assertJsonPath('data.type', 'missed_medication')
            ->assertJsonPath('data.related_id', 15);

        $this->assertNotNull($notification->fresh()->read_at);
    }

    public function test_notification_detail_blocks_other_users(): void
    {
        $owner = User::factory()->create([
            'first_name' => 'Care',
            'last_name' => 'Owner',
            'phone' => '01000000002',
            'role' => 'caregiver',
        ]);
        $other = User::factory()->create([
            'first_name' => 'Patient',
            'last_name' => 'Other',
            'phone' => '01000000003',
            'role' => 'patient',
        ]);
        $notification = Notification::create([
            'notifiable_type' => User::class,
            'notifiable_id' => $owner->id,
            'type' => 'system',
            'title' => 'Private',
            'message' => 'Only owner can read this.',
            'category' => 'system',
        ]);

        Sanctum::actingAs($other);

        $this->getJson("/api/notifications/{$notification->id}")
            ->assertForbidden();
    }
}
