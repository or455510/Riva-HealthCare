<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Clinic;
use App\Models\ClinicService;
use App\Models\ClinicUser;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase C (Services & Prices, principal-engineering directive): proves
 * ClinicServiceController's CRUD + services.manage enforcement, cross-clinic
 * isolation, deletion-blocked-by-history, and — critically — that
 * AppointmentController::store() actually uses a service's price (and
 * rejects an invalid/inactive/cross-clinic one) rather than only ever
 * falling back to Doctor::fee.
 */
class ClinicServiceManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_create_list_update_and_delete_a_service(): void
    {
        $clinic = $this->makeClinicWithOwner();
        Sanctum::actingAs($clinic['ownerUser']);

        $create = $this->postJson('/api/clinic/services', [
            'name' => 'General Consultation',
            'description' => 'Standard visit',
            'price' => 150,
            'duration_minutes' => 30,
        ])->assertStatus(201)->assertJsonPath('data.name', 'General Consultation');

        $serviceId = $create->json('data.id');

        $this->getJson('/api/clinic/services')->assertOk()->assertJsonCount(1, 'data');

        $this->patchJson("/api/clinic/services/{$serviceId}", ['price' => 175])
            ->assertOk()
            ->assertJsonPath('data.price', '175.00');

        $this->deleteJson("/api/clinic/services/{$serviceId}")->assertOk();
        $this->getJson('/api/clinic/services')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_staff_member_without_manage_services_permission_is_denied(): void
    {
        $clinic = $this->makeClinicWithOwner();
        $staffUser = $this->addStaffMember($clinic['clinic'], [ClinicUser::PERMISSION_REGISTER_PATIENTS]);

        Sanctum::actingAs($staffUser);

        $this->postJson('/api/clinic/services', [
            'name' => 'Blocked',
            'price' => 100,
        ])->assertForbidden();
    }

    public function test_staff_member_with_manage_services_permission_can_create_a_service(): void
    {
        $clinic = $this->makeClinicWithOwner();
        $staffUser = $this->addStaffMember($clinic['clinic'], [ClinicUser::PERMISSION_MANAGE_SERVICES]);

        Sanctum::actingAs($staffUser);

        $this->postJson('/api/clinic/services', [
            'name' => 'Follow-up',
            'price' => 80,
        ])->assertStatus(201);
    }

    public function test_staff_member_without_manage_services_can_still_view_the_price_list(): void
    {
        $clinic = $this->makeClinicWithOwner();
        $service = ClinicService::create([
            'clinic_id' => $clinic['clinic']->id,
            'name' => 'Visible Service',
            'price' => 50,
            'is_active' => true,
        ]);
        $staffUser = $this->addStaffMember($clinic['clinic'], [ClinicUser::PERMISSION_REGISTER_PATIENTS]);

        Sanctum::actingAs($staffUser);

        $this->getJson('/api/clinic/services')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_clinic_a_owner_cannot_update_or_delete_clinic_bs_service(): void
    {
        $clinicA = $this->makeClinicWithOwner();
        $clinicB = $this->makeClinicWithOwner();
        $serviceB = ClinicService::create([
            'clinic_id' => $clinicB['clinic']->id,
            'name' => 'Clinic B Service',
            'price' => 60,
            'is_active' => true,
        ]);

        Sanctum::actingAs($clinicA['ownerUser']);

        $this->patchJson("/api/clinic/services/{$serviceB->id}", ['price' => 999])->assertNotFound();
        $this->deleteJson("/api/clinic/services/{$serviceB->id}")->assertNotFound();
    }

    public function test_deleting_a_service_with_appointment_history_is_blocked(): void
    {
        $clinic = $this->makeClinicWithOwner();
        $service = ClinicService::create([
            'clinic_id' => $clinic['clinic']->id,
            'name' => 'Booked Service',
            'price' => 120,
            'is_active' => true,
        ]);
        $this->makeAppointmentWithService($clinic['doctor'], $service);

        Sanctum::actingAs($clinic['ownerUser']);

        $this->deleteJson("/api/clinic/services/{$service->id}")->assertStatus(409);

        $this->patchJson("/api/clinic/services/{$service->id}", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);
    }

    public function test_booking_with_a_valid_service_derives_amount_from_the_service_price(): void
    {
        $clinic = $this->makeClinicWithOwner();
        $service = ClinicService::create([
            'clinic_id' => $clinic['clinic']->id,
            'name' => 'Priced Visit',
            'price' => 225,
            'is_active' => true,
        ]);

        Sanctum::actingAs($clinic['patientUser']);

        $response = $this->postJson('/api/appointments', [
            'doctor_id' => $clinic['doctor']->id,
            'service_id' => $service->id,
            'appointment_date' => today()->addDay()->toDateString(),
            'appointment_time' => '10:00',
            'type' => 'offline',
        ])->assertStatus(201);

        $this->assertEquals('225.00', $response->json('data.amount'));
    }

    public function test_booking_with_no_service_id_still_falls_back_to_doctor_fee(): void
    {
        $clinic = $this->makeClinicWithOwner();

        Sanctum::actingAs($clinic['patientUser']);

        $response = $this->postJson('/api/appointments', [
            'doctor_id' => $clinic['doctor']->id,
            'appointment_date' => today()->addDay()->toDateString(),
            'appointment_time' => '10:00',
            'type' => 'offline',
        ])->assertStatus(201);

        $this->assertEquals($clinic['doctor']->fee, $response->json('data.amount'));
    }

    public function test_booking_with_an_inactive_service_is_rejected(): void
    {
        $clinic = $this->makeClinicWithOwner();
        $service = ClinicService::create([
            'clinic_id' => $clinic['clinic']->id,
            'name' => 'Retired Service',
            'price' => 100,
            'is_active' => false,
        ]);

        Sanctum::actingAs($clinic['patientUser']);

        $this->postJson('/api/appointments', [
            'doctor_id' => $clinic['doctor']->id,
            'service_id' => $service->id,
            'appointment_date' => today()->addDay()->toDateString(),
            'appointment_time' => '10:00',
            'type' => 'offline',
        ])->assertStatus(422);
    }

    public function test_booking_with_another_clinics_service_is_rejected(): void
    {
        $clinic = $this->makeClinicWithOwner();
        $otherClinic = $this->makeClinicWithOwner();
        $foreignService = ClinicService::create([
            'clinic_id' => $otherClinic['clinic']->id,
            'name' => 'Foreign Service',
            'price' => 100,
            'is_active' => true,
        ]);

        Sanctum::actingAs($clinic['patientUser']);

        $this->postJson('/api/appointments', [
            'doctor_id' => $clinic['doctor']->id,
            'service_id' => $foreignService->id,
            'appointment_date' => today()->addDay()->toDateString(),
            'appointment_time' => '10:00',
            'type' => 'offline',
        ])->assertStatus(422);
    }

    private function makeClinicWithOwner(): array
    {
        $ownerUser = $this->makeUser('doctor', 'owner');
        $doctor = Doctor::create(['user_id' => $ownerUser->id, 'fee' => 300]);

        $clinic = Clinic::create([
            'name' => 'Services Test Clinic',
            'slug' => 'services-test-clinic-'.uniqid(),
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

        return compact('clinic', 'ownerUser', 'doctor', 'patient', 'patientUser');
    }

    private function addStaffMember(Clinic $clinic, array $permissions): User
    {
        $staffUser = $this->makeUser('patient', 'staff');
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

    private function makeAppointmentWithService(Doctor $doctor, ClinicService $service): Appointment
    {
        $patientUser = $this->makeUser('patient', 'visit-'.uniqid());
        $patient = Patient::create(['user_id' => $patientUser->id]);

        return Appointment::create([
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
            'service_id' => $service->id,
            'appointment_date' => today()->toDateString(),
            'appointment_time' => '10:00',
            'type' => 'offline',
            'amount' => $service->price,
            'payment_status' => 'paid',
            'status' => 'confirmed',
        ]);
    }

    private function makeUser(string $role, string $label): User
    {
        return User::create([
            'first_name' => ucfirst($role),
            'last_name' => 'Services'.$label,
            'name' => ucfirst($role).' Services '.$label,
            'email' => strtolower($role).'-services-'.strtolower($label).'-'.uniqid().'@riva.test',
            'password' => 'password',
            'role' => $role,
            'is_active' => true,
            'profile_completed_at' => now(),
        ]);
    }
}
