<?php

namespace Tests\Feature;

use App\Models\Clinic;
use App\Models\ClinicUser;
use App\Models\Doctor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BackfillSanityTest extends TestCase
{
    use RefreshDatabase;

    public function test_backfill_creates_clinic_of_one_for_existing_doctor(): void
    {
        $user = User::create([
            'first_name' => 'Sanity', 'last_name' => 'Doc', 'name' => 'Sanity Doc',
            'email' => 'sanity@riva.test', 'password' => 'password', 'role' => 'doctor',
            'is_active' => true, 'profile_completed_at' => now(),
        ]);
        $doctor = Doctor::create(['user_id' => $user->id]);

        // Backfill migration already ran as part of RefreshDatabase's migrate — but
        // it ran BEFORE this doctor existed. Re-run it manually to prove idempotency
        // and correctness against doctors created after initial migration too.
        $migration = require database_path('migrations/2026_08_23_000006_backfill_clinic_of_one_for_doctors.php');
        $migration->up();

        $membership = ClinicUser::where('user_id', $user->id)->first();
        $this->assertNotNull($membership, 'Expected a clinic_user row to be backfilled for the doctor.');
        $this->assertSame('owner', $membership->role);
        $this->assertSame('active', $membership->status);

        $clinic = Clinic::find($membership->clinic_id);
        $this->assertNotNull($clinic);
        $this->assertSame($user->id, $clinic->owner_user_id);
        $this->assertSame('active', $clinic->status);

        // Idempotency: running it again must not create a duplicate.
        $migration->up();
        $this->assertSame(1, ClinicUser::where('user_id', $user->id)->count());
    }
}
