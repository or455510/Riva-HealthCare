<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Clinic;
use App\Models\ClinicUser;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase C (Queue + Appointment operational workflow, principal-engineering
 * directive): proves the QueueController state machine, clinic scoping, and
 * — critically — that queue.manage is actually enforced via
 * ClinicUser::hasPermission() (the first real consumer of the Slice 3 RBAC
 * model), not merely accepted from any active clinic member.
 */
class QueueManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_check_in_call_start_and_complete_a_todays_appointment(): void
    {
        $clinic = $this->makeClinicWithOwner();
        $appointment = $this->makeAppointment($clinic['doctor'], today: true);

        Sanctum::actingAs($clinic['ownerUser']);

        $this->getJson('/api/clinic/queue')->assertOk()->assertJsonCount(1, 'data');

        $this->postJson("/api/clinic/queue/{$appointment->id}/check-in")
            ->assertOk()
            ->assertJsonPath('data.queue_status', 'waiting')
            ->assertJsonPath('data.queue_position', 1);

        $this->postJson("/api/clinic/queue/{$appointment->id}/call")
            ->assertOk()
            ->assertJsonPath('data.queue_status', 'called');

        $this->postJson("/api/clinic/queue/{$appointment->id}/start")
            ->assertOk()
            ->assertJsonPath('data.queue_status', 'in_service');

        $this->postJson("/api/clinic/queue/{$appointment->id}/complete")
            ->assertOk()
            ->assertJsonPath('data.queue_status', 'completed');

        $this->assertSame('completed', $appointment->fresh()->status);
    }

    public function test_staff_member_with_queue_manage_permission_can_check_in_a_patient(): void
    {
        $clinic = $this->makeClinicWithOwner();
        $staffUser = $this->addStaffMember($clinic['clinic'], [ClinicUser::PERMISSION_MANAGE_QUEUE]);
        $appointment = $this->makeAppointment($clinic['doctor'], today: true);

        Sanctum::actingAs($staffUser);

        $this->postJson("/api/clinic/queue/{$appointment->id}/check-in")
            ->assertOk()
            ->assertJsonPath('data.queue_status', 'waiting');
    }

    public function test_staff_member_without_queue_manage_permission_is_denied(): void
    {
        $clinic = $this->makeClinicWithOwner();
        $staffUser = $this->addStaffMember($clinic['clinic'], [ClinicUser::PERMISSION_REGISTER_PATIENTS]);
        $appointment = $this->makeAppointment($clinic['doctor'], today: true);

        Sanctum::actingAs($staffUser);

        $this->postJson("/api/clinic/queue/{$appointment->id}/check-in")->assertForbidden();
    }

    public function test_plain_doctor_member_can_view_but_not_manage_the_queue(): void
    {
        $clinic = $this->makeClinicWithOwner();
        $secondDoctorUser = $this->makeUser('doctor', 'second');
        Doctor::create(['user_id' => $secondDoctorUser->id, 'fee' => 200]);
        ClinicUser::create([
            'clinic_id' => $clinic['clinic']->id,
            'user_id' => $secondDoctorUser->id,
            'role' => ClinicUser::ROLE_DOCTOR,
            'status' => 'active',
            'joined_at' => now(),
        ]);
        $appointment = $this->makeAppointment($clinic['doctor'], today: true);

        Sanctum::actingAs($secondDoctorUser);

        $this->getJson('/api/clinic/queue')->assertOk();
        $this->postJson("/api/clinic/queue/{$appointment->id}/check-in")->assertForbidden();
    }

    public function test_manager_of_clinic_a_cannot_manage_clinic_b_appointment_queue(): void
    {
        $clinicA = $this->makeClinicWithOwner();
        $clinicB = $this->makeClinicWithOwner();
        $appointmentB = $this->makeAppointment($clinicB['doctor'], today: true);

        Sanctum::actingAs($clinicA['ownerUser']);

        $this->postJson("/api/clinic/queue/{$appointmentB->id}/check-in")->assertNotFound();
    }

    public function test_future_dated_appointment_cannot_be_checked_in(): void
    {
        $clinic = $this->makeClinicWithOwner();
        $appointment = $this->makeAppointment($clinic['doctor'], today: false);

        Sanctum::actingAs($clinic['ownerUser']);

        $this->postJson("/api/clinic/queue/{$appointment->id}/check-in")
            ->assertStatus(422);
    }

    public function test_cancelled_appointment_cannot_be_checked_in(): void
    {
        $clinic = $this->makeClinicWithOwner();
        $appointment = $this->makeAppointment($clinic['doctor'], today: true);
        $appointment->update(['status' => 'cancelled']);

        Sanctum::actingAs($clinic['ownerUser']);

        $this->postJson("/api/clinic/queue/{$appointment->id}/check-in")
            ->assertStatus(422);
    }

    public function test_checking_in_the_same_appointment_twice_is_rejected(): void
    {
        $clinic = $this->makeClinicWithOwner();
        $appointment = $this->makeAppointment($clinic['doctor'], today: true);

        Sanctum::actingAs($clinic['ownerUser']);

        $this->postJson("/api/clinic/queue/{$appointment->id}/check-in")->assertOk();
        $this->postJson("/api/clinic/queue/{$appointment->id}/check-in")->assertStatus(409);
    }

    public function test_completing_a_visit_before_check_in_is_rejected(): void
    {
        $clinic = $this->makeClinicWithOwner();
        $appointment = $this->makeAppointment($clinic['doctor'], today: true);

        Sanctum::actingAs($clinic['ownerUser']);

        $this->postJson("/api/clinic/queue/{$appointment->id}/complete")->assertStatus(422);
    }

    public function test_no_show_and_skip_transitions(): void
    {
        $clinic = $this->makeClinicWithOwner();
        $noShowAppointment = $this->makeAppointment($clinic['doctor'], today: true);
        $skippedAppointment = $this->makeAppointment($clinic['doctor'], today: true);

        Sanctum::actingAs($clinic['ownerUser']);

        $this->postJson("/api/clinic/queue/{$noShowAppointment->id}/check-in")->assertOk();
        $this->postJson("/api/clinic/queue/{$noShowAppointment->id}/no-show")
            ->assertOk()->assertJsonPath('data.queue_status', 'no_show');

        $this->postJson("/api/clinic/queue/{$skippedAppointment->id}/check-in")->assertOk();
        $this->postJson("/api/clinic/queue/{$skippedAppointment->id}/skip")
            ->assertOk()->assertJsonPath('data.queue_status', 'skipped');

        // A skipped patient can be re-checked-in later the same day.
        $this->postJson("/api/clinic/queue/{$skippedAppointment->id}/check-in")
            ->assertOk()->assertJsonPath('data.queue_status', 'waiting');
    }

    public function test_remove_clears_queue_state(): void
    {
        $clinic = $this->makeClinicWithOwner();
        $appointment = $this->makeAppointment($clinic['doctor'], today: true);

        Sanctum::actingAs($clinic['ownerUser']);

        $this->postJson("/api/clinic/queue/{$appointment->id}/check-in")->assertOk();
        $this->postJson("/api/clinic/queue/{$appointment->id}/remove")
            ->assertOk()
            ->assertJsonPath('data.queue_status', null)
            ->assertJsonPath('data.queue_position', null);
    }

    public function test_my_context_reports_no_clinic_for_a_patient_without_membership(): void
    {
        $patientUser = $this->makeUser('patient', 'lone');
        Patient::create(['user_id' => $patientUser->id]);

        Sanctum::actingAs($patientUser);

        $this->getJson('/api/clinic/my-context')
            ->assertOk()
            ->assertJsonPath('data.has_clinic', false);
    }

    public function test_my_context_reports_clinic_and_role_for_an_owner(): void
    {
        $clinic = $this->makeClinicWithOwner();

        Sanctum::actingAs($clinic['ownerUser']);

        $this->getJson('/api/clinic/my-context')
            ->assertOk()
            ->assertJsonPath('data.has_clinic', true)
            ->assertJsonPath('data.ambiguous', false)
            ->assertJsonPath('data.clinics.0.role', ClinicUser::ROLE_OWNER);
    }

    private function makeClinicWithOwner(): array
    {
        $ownerUser = $this->makeUser('doctor', 'owner');
        $doctor = Doctor::create(['user_id' => $ownerUser->id, 'fee' => 300]);

        $clinic = Clinic::create([
            'name' => 'Queue Test Clinic',
            'slug' => 'queue-test-clinic-'.uniqid(),
            'owner_user_id' => $ownerUser->id,
            'status' => 'active',
        ]);
        ClinicUser::create([
            'clinic_id' => $clinic->id,
            'user_id' => $ownerUser->id,
            'role' => ClinicUser::ROLE_OWNER,
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $patientUser = $this->makeUser('patient', 'p');
        $patient = Patient::create(['user_id' => $patientUser->id]);

        return compact('clinic', 'ownerUser', 'doctor', 'patient');
    }

    private function addStaffMember(Clinic $clinic, array $permissions): User
    {
        $staffUser = $this->makeUser('patient', 'staff'); // platform role is irrelevant to clinic role
        ClinicUser::create([
            'clinic_id' => $clinic->id,
            'user_id' => $staffUser->id,
            'role' => ClinicUser::ROLE_STAFF,
            'status' => 'active',
            'permissions' => $permissions,
            'joined_at' => now(),
        ]);

        return $staffUser;
    }

    private function makeAppointment(Doctor $doctor, bool $today): Appointment
    {
        $patientUser = $this->makeUser('patient', 'visit-'.uniqid());
        $patient = Patient::create(['user_id' => $patientUser->id]);

        return Appointment::create([
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
            'appointment_date' => $today ? today()->toDateString() : today()->addDay()->toDateString(),
            'appointment_time' => '10:00',
            'type' => 'offline',
            'amount' => $doctor->fee ?? 300,
            'payment_status' => 'paid',
            'status' => 'confirmed',
        ]);
    }

    private function makeUser(string $role, string $label): User
    {
        return User::create([
            'first_name' => ucfirst($role),
            'last_name' => 'Queue'.$label,
            'name' => ucfirst($role).' Queue '.$label,
            'email' => strtolower($role).'-queue-'.strtolower($label).'-'.uniqid().'@riva.test',
            'password' => 'password',
            'role' => $role,
            'is_active' => true,
            'profile_completed_at' => now(),
        ]);
    }
}
