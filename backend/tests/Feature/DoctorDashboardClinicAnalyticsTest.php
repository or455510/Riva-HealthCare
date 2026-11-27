<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * RIVA-150 (product-owner request, 2026-08-25, page-by-page dashboard
 * rebuild — Doctor Dashboard homepage): "where are the boxes with the
 * clinic's analytics — case count, consultations, checkups, daily
 * checkups, revenue/profit?" These tests prove
 * DashboardController::clinicAnalytics() computes real, correctly-scoped
 * numbers from Appointment/Payment rows rather than fabricating anything,
 * and that another doctor's appointments/payments never leak into the
 * totals (the same cross-doctor isolation bar every other dashboard field
 * in this controller is held to).
 */
class DoctorDashboardClinicAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_today_appointment_and_checkup_counts_are_real_and_scoped_to_the_doctor(): void
    {
        [$doctorUser, $doctor] = $this->makeDoctor();
        [, $otherDoctor] = $this->makeDoctor();
        $patient = $this->makePatientRecord();

        $this->makeAppointment($doctor, $patient, today()->toDateString(), 'completed', 100);
        $this->makeAppointment($doctor, $patient, today()->toDateString(), 'confirmed', 100);
        $this->makeAppointment($doctor, $patient, today()->addDay()->toDateString(), 'completed', 100);
        // Another doctor's appointment today must never count toward this doctor's totals.
        $this->makeAppointment($otherDoctor, $patient, today()->toDateString(), 'completed', 500);

        Sanctum::actingAs($doctorUser);
        $data = $this->getJson('/api/dashboard/doctor')->assertOk()->json('data');

        $this->assertSame(2, $data['today_appointments_count']);
        $this->assertSame(1, $data['today_completed_checkups']);
        $this->assertSame(1, $data['today_pending_appointments']);
    }

    public function test_total_and_monthly_consultations_only_count_completed_appointments(): void
    {
        [$doctorUser, $doctor] = $this->makeDoctor();
        $patient = $this->makePatientRecord();

        $this->makeAppointment($doctor, $patient, today()->toDateString(), 'completed', 100);
        $this->makeAppointment($doctor, $patient, today()->subDays(2)->toDateString(), 'completed', 100);
        $this->makeAppointment($doctor, $patient, today()->toDateString(), 'cancelled', 100);
        $this->makeAppointment($doctor, $patient, today()->toDateString(), 'pending', 100);

        Sanctum::actingAs($doctorUser);
        $data = $this->getJson('/api/dashboard/doctor')->assertOk()->json('data');

        $this->assertSame(2, $data['total_consultations']);
        $this->assertSame(2, $data['monthly_consultations']);
        $this->assertSame(1, $data['cancelled_this_month']);
    }

    /**
     * Dashboard-spec gap #1 (DOCTOR_ERP_DASHBOARD_SPEC.md §10.4/§11): a
     * same-day cancellation count, distinct from the pre-existing
     * month-scoped `cancelled_this_month` — without this, the frontend
     * would have to mislabel a monthly figure as "today," which the spec
     * explicitly forbids.
     */
    public function test_today_cancelled_count_is_scoped_to_today_and_to_the_doctor(): void
    {
        [$doctorUser, $doctor] = $this->makeDoctor();
        [, $otherDoctor] = $this->makeDoctor();
        $patient = $this->makePatientRecord();

        $this->makeAppointment($doctor, $patient, today()->toDateString(), 'cancelled', 100);
        $this->makeAppointment($doctor, $patient, today()->toDateString(), 'cancelled', 100);
        $this->makeAppointment($doctor, $patient, today()->subDay()->toDateString(), 'cancelled', 100);
        $this->makeAppointment($otherDoctor, $patient, today()->toDateString(), 'cancelled', 100);

        Sanctum::actingAs($doctorUser);
        $data = $this->getJson('/api/dashboard/doctor')->assertOk()->json('data');

        $this->assertSame(2, $data['today_cancelled_count']);
    }

    public function test_revenue_is_computed_from_succeeded_payments_minus_refunds_and_scoped_to_the_doctor(): void
    {
        [$doctorUser, $doctor] = $this->makeDoctor();
        [, $otherDoctor] = $this->makeDoctor();
        $patient = $this->makePatientRecord();

        $paidAppointment = $this->makeAppointment($doctor, $patient, today()->toDateString(), 'completed', 300);
        $this->makePayment($paidAppointment, 300, 'succeeded', 50); // net 250
        $refundedAppointment = $this->makeAppointment($doctor, $patient, today()->toDateString(), 'completed', 200);
        $this->makePayment($refundedAppointment, 200, 'succeeded', 0);
        $failedAppointment = $this->makeAppointment($doctor, $patient, today()->toDateString(), 'cancelled', 400);
        $this->makePayment($failedAppointment, 400, 'failed', 0);

        $otherAppointment = $this->makeAppointment($otherDoctor, $patient, today()->toDateString(), 'completed', 9999);
        $this->makePayment($otherAppointment, 9999, 'succeeded', 0);

        Sanctum::actingAs($doctorUser);
        $data = $this->getJson('/api/dashboard/doctor')->assertOk()->json('data');

        $this->assertEquals(450.0, $data['revenue_total']); // 250 + 200, failed payment excluded, other doctor excluded
        $this->assertEquals(450.0, $data['revenue_this_month']);
        $this->assertEquals(450.0, $data['revenue_today']);
        $this->assertSame('EGP', $data['currency']);
    }

    /**
     * Dashboard-spec gap #2 (DOCTOR_ERP_DASHBOARD_SPEC.md §10.4/§11): a
     * same-day revenue figure distinct from `revenue_this_month`, proven
     * separately from the "everything happened today" test above by giving
     * a payment a real, older `created_at` so only the month-scoped figure
     * should include it.
     */
    public function test_revenue_today_excludes_a_payment_from_earlier_this_month(): void
    {
        [$doctorUser, $doctor] = $this->makeDoctor();
        $patient = $this->makePatientRecord();

        $earlierAppointment = $this->makeAppointment($doctor, $patient, today()->subDays(3)->toDateString(), 'completed', 300);
        $earlierPayment = $this->makePayment($earlierAppointment, 300, 'succeeded', 0);
        $earlierPayment->forceFill(['created_at' => now()->subDays(3)])->save();

        $todayAppointment = $this->makeAppointment($doctor, $patient, today()->toDateString(), 'completed', 150);
        $this->makePayment($todayAppointment, 150, 'succeeded', 0);

        Sanctum::actingAs($doctorUser);
        $data = $this->getJson('/api/dashboard/doctor')->assertOk()->json('data');

        $this->assertEquals(150.0, $data['revenue_today']);
        $this->assertEquals(450.0, $data['revenue_this_month']);
    }

    public function test_average_revenue_per_visit_guards_against_division_by_zero(): void
    {
        [$doctorUser, $doctor] = $this->makeDoctor();

        Sanctum::actingAs($doctorUser);
        $data = $this->getJson('/api/dashboard/doctor')->assertOk()->json('data');

        $this->assertSame(0, $data['average_revenue_per_visit']);
        $this->assertEquals(0, $data['revenue_total']);
    }

    private function makePayment(Appointment $appointment, float $amount, string $status, float $refunded): Payment
    {
        return Payment::create([
            'appointment_id' => $appointment->id,
            'amount' => $amount,
            'currency' => 'EGP',
            'status' => $status,
            'gateway' => 'manual',
            'refunded_amount' => $refunded,
        ]);
    }

    private function makeAppointment(Doctor $doctor, Patient $patient, string $date, string $status, float $amount): Appointment
    {
        return Appointment::create([
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
            'appointment_date' => $date,
            'appointment_time' => '10:00',
            'type' => 'offline',
            'amount' => $amount,
            'payment_status' => $status === 'completed' ? 'paid' : 'unpaid',
            'status' => $status,
        ]);
    }

    private function makePatientRecord(): Patient
    {
        $user = User::create([
            'first_name' => 'Patient', 'last_name' => uniqid('Dash'),
            'name' => 'Patient Dash', 'email' => uniqid('patient').'@riva.test',
            'password' => 'password', 'role' => 'patient', 'is_active' => true,
            'profile_completed_at' => now(),
        ]);

        return Patient::create(['user_id' => $user->id]);
    }

    private function makeDoctor(): array
    {
        $user = User::create([
            'first_name' => 'Doctor', 'last_name' => uniqid('Dash'),
            'name' => 'Doctor Dash', 'email' => uniqid('doctor').'@riva.test',
            'password' => 'password', 'role' => 'doctor', 'is_active' => true,
            'profile_completed_at' => now(),
        ]);

        return [$user, Doctor::create(['user_id' => $user->id, 'fee' => 300])];
    }
}
