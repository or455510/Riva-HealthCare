<?php

namespace Tests\Feature;

use App\Models\Doctor;
use App\Models\DoctorPaymentMethod;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * RIVA-130 follow-up (product-owner request, recorded 2026-08-25): "let the
 * DOCTOR be the one who chooses which payment method(s) they personally
 * accept... never a fixed set shown to every patient." Proves: the catalog
 * is a fixed, real list; a doctor can add/update/remove only their OWN
 * payment methods; a method needing an account detail (a wallet number,
 * an InstaPay handle) is rejected without one; and the public,
 * patient-facing endpoint only ever returns methods this doctor actively
 * chose to expose (inactive/unconfigured methods never leak through).
 */
class DoctorPaymentMethodTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_is_a_fixed_real_list_not_doctor_specific(): void
    {
        [$doctorUser] = $this->makeDoctor();
        Sanctum::actingAs($doctorUser);

        $response = $this->getJson('/api/payment-methods/catalog')->assertOk();
        $keys = collect($response->json('data'))->pluck('key');

        $this->assertContains('vodafone_cash', $keys);
        $this->assertContains('instapay', $keys);
        $this->assertContains('card', $keys);
    }

    public function test_doctor_can_add_a_wallet_method_with_an_account_number(): void
    {
        [$doctorUser, $doctor] = $this->makeDoctor();
        Sanctum::actingAs($doctorUser);

        $this->postJson('/api/doctor/payment-methods', [
            'method' => 'vodafone_cash',
            'account_label' => '01012345678',
        ])->assertStatus(201)
            ->assertJsonPath('data.method', 'vodafone_cash')
            ->assertJsonPath('data.account_label', '01012345678');

        $this->assertDatabaseHas('doctor_payment_methods', [
            'doctor_id' => $doctor->id,
            'method' => 'vodafone_cash',
            'account_label' => '01012345678',
        ]);
    }

    public function test_a_wallet_method_without_an_account_number_is_rejected(): void
    {
        [$doctorUser] = $this->makeDoctor();
        Sanctum::actingAs($doctorUser);

        $this->postJson('/api/doctor/payment-methods', [
            'method' => 'instapay',
        ])->assertStatus(422);
    }

    public function test_cash_at_clinic_needs_no_account_number(): void
    {
        [$doctorUser] = $this->makeDoctor();
        Sanctum::actingAs($doctorUser);

        $this->postJson('/api/doctor/payment-methods', [
            'method' => 'cash',
        ])->assertStatus(201);
    }

    public function test_saving_the_same_method_again_updates_it_instead_of_duplicating(): void
    {
        [$doctorUser, $doctor] = $this->makeDoctor();
        Sanctum::actingAs($doctorUser);

        $this->postJson('/api/doctor/payment-methods', ['method' => 'orange_cash', 'account_label' => '01111111111'])
            ->assertStatus(201);
        $this->postJson('/api/doctor/payment-methods', ['method' => 'orange_cash', 'account_label' => '01222222222'])
            ->assertStatus(201);

        $this->assertSame(1, DoctorPaymentMethod::where('doctor_id', $doctor->id)->where('method', 'orange_cash')->count());
        $this->assertDatabaseHas('doctor_payment_methods', ['doctor_id' => $doctor->id, 'method' => 'orange_cash', 'account_label' => '01222222222']);
    }

    public function test_a_doctor_cannot_update_or_delete_another_doctors_payment_method(): void
    {
        [, $doctorA] = $this->makeDoctor();
        [$doctorBUser] = $this->makeDoctor();

        $method = DoctorPaymentMethod::create(['doctor_id' => $doctorA->id, 'method' => 'card', 'is_active' => true]);

        Sanctum::actingAs($doctorBUser);

        $this->patchJson("/api/doctor/payment-methods/{$method->id}", ['is_active' => false])->assertStatus(404);
        $this->deleteJson("/api/doctor/payment-methods/{$method->id}")->assertStatus(404);
    }

    public function test_public_endpoint_only_returns_this_doctors_active_methods(): void
    {
        [, $doctor] = $this->makeDoctor();
        DoctorPaymentMethod::create(['doctor_id' => $doctor->id, 'method' => 'vodafone_cash', 'account_label' => '01099999999', 'is_active' => true]);
        DoctorPaymentMethod::create(['doctor_id' => $doctor->id, 'method' => 'card', 'is_active' => false]);

        $response = $this->getJson("/api/doctors/{$doctor->id}/payment-methods")->assertOk();
        $methods = collect($response->json('data'));

        $this->assertCount(1, $methods);
        $this->assertSame('vodafone_cash', $methods->first()['method']);
    }

    public function test_a_patient_cannot_manage_doctor_payment_methods(): void
    {
        $patientUser = User::create([
            'first_name' => 'Pat', 'last_name' => uniqid('Pay'),
            'name' => 'Pat Pay', 'email' => uniqid('patient').'@riva.test',
            'password' => 'password', 'role' => 'patient', 'is_active' => true,
        ]);
        Sanctum::actingAs($patientUser);

        $this->postJson('/api/doctor/payment-methods', ['method' => 'card'])->assertStatus(403);
    }

    private function makeDoctor(): array
    {
        $user = User::create([
            'first_name' => 'Doctor', 'last_name' => uniqid('Pay'),
            'name' => 'Doctor Pay', 'email' => uniqid('doctor').'@riva.test',
            'password' => 'password', 'role' => 'doctor', 'is_active' => true,
            'profile_completed_at' => now(),
        ]);

        return [$user, Doctor::create(['user_id' => $user->id, 'fee' => 300])];
    }
}
