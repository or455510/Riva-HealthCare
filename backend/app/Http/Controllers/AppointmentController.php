<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\AppointmentBookingLog;
use App\Models\Doctor;
use App\Models\PatientDoctorRelationship;
use App\Services\NotificationService;
use Illuminate\Http\Request;

class AppointmentController extends Controller
{
    public function __construct(private readonly NotificationService $notificationService)
    {
    }

    public function index(Request $request)
    {
        $user = $request->user();
        $query = Appointment::with(['patient.user', 'doctor.user', 'hospital']);

        if ($user->role === 'patient' && $user->patient) {
            $query->where('patient_id', $user->patient->id);
        } elseif ($user->role === 'doctor' && $user->doctor) {
            $query->where('doctor_id', $user->doctor->id);
        }

        return $this->success($query->latest()->get());
    }

    public function store(Request $request)
    {
        $patient = $request->user()->patient;
        abort_unless($patient, 403);

        $data = $request->validate([
            'doctor_id' => ['required', 'exists:doctors,id'],
            'hospital_id' => ['nullable', 'exists:hospitals,id'],
            'appointment_date' => ['required', 'date', 'after_or_equal:today'],
            'appointment_time' => ['required', 'date_format:H:i'],
            'type' => ['nullable', 'in:online,offline,inperson'],
            'amount' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
        ]);

        $doctor = Doctor::with('user')->findOrFail($data['doctor_id']);

        $appointment = Appointment::create([
            ...$data,
            'patient_id' => $patient->id,
            'amount' => $data['amount'] ?? 0,
            'status' => 'pending_payment',
            'payment_status' => 'unpaid',
        ]);

        AppointmentBookingLog::create([
            'appointment_id' => $appointment->id,
            'user_id' => $request->user()->id,
            'action' => 'created',
            'notes' => $data['notes'] ?? null,
        ]);

        $relationship = PatientDoctorRelationship::query()
            ->where('patient_id', $patient->id)
            ->where('doctor_id', $doctor->id)
            ->latest()
            ->first();

        if (!$relationship) {
            PatientDoctorRelationship::create([
                'patient_id' => $patient->id,
                'doctor_id' => $doctor->id,
                'status' => 'pending',
                'requested_at' => now(),
            ]);
        } elseif (in_array($relationship->status, ['rejected', 'ended'], true)) {
            $relationship->update([
                'status' => 'pending',
                'requested_at' => now(),
                'responded_at' => null,
                'ended_at' => null,
            ]);
        }

        $this->notificationService->notify(
            $request->user(),
            'appointment_pending_payment',
            'Payment required for consultation',
            'Your consultation slot is reserved. Complete payment to confirm the appointment.',
            [
                'appointment_id' => $appointment->id,
                'doctor_id' => $doctor->id,
                'doctor_name' => $doctor->user?->name,
                'appointment_date' => $appointment->appointment_date,
                'appointment_time' => $appointment->appointment_time,
                'amount' => $appointment->amount,
            ],
            '/appointments/book/' . $doctor->id,
            false
        );

        return $this->success($appointment, 'Appointment created successfully', 201);
    }

    public function show(Request $request, Appointment $appointment)
    {
        $this->authorizeAppointment($request, $appointment);
        return $this->success($appointment->load(['patient.user', 'doctor.user', 'hospital']));
    }

    public function update(Request $request, Appointment $appointment)
    {
        $this->authorizeAppointment($request, $appointment);
        $data = $request->validate([
            'appointment_date' => ['nullable', 'date'],
            'appointment_time' => ['nullable', 'date_format:H:i'],
            'type' => ['nullable', 'in:online,offline,inperson'],
            'amount' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
            'status' => ['nullable', 'in:pending_payment,pending,confirmed,cancelled,completed'],
        ]);

        $appointment->update($data);
        return $this->success($appointment->fresh(), 'Appointment updated successfully');
    }

    public function confirm(Request $request, Appointment $appointment)
    {
        $this->authorizeAppointment($request, $appointment);
        if ($appointment->payment_status !== 'paid') {
            return $this->error('Payment must be completed before confirming this appointment.', 422);
        }
        return $this->updateStatus($appointment, 'confirmed');
    }
    public function cancel(Request $request, Appointment $appointment) { $this->authorizeAppointment($request, $appointment); return $this->updateStatus($appointment, 'cancelled'); }
    public function complete(Request $request, Appointment $appointment)
    {
        $this->authorizeAppointment($request, $appointment);
        if ($appointment->payment_status !== 'paid') {
            return $this->error('Payment must be completed before completing this appointment.', 422);
        }
        return $this->updateStatus($appointment, 'completed');
    }

    public function pay(Request $request, Appointment $appointment)
    {
        if (!$this->canPay($request, $appointment)) {
            return $this->error('Only the patient who owns this appointment can pay for it.', 403);
        }

        if ($appointment->payment_status === 'paid') {
            return $this->error('This appointment is already paid.', 409);
        }

        if ($appointment->status === 'cancelled') {
            return $this->error('Cancelled appointments cannot be paid.', 422);
        }

        $appointment->update([
            'payment_status' => 'paid',
            'status' => 'confirmed',
        ]);

        $appointment->load(['patient.user', 'doctor.user']);

        if ($appointment->doctor?->user) {
            $patientName = $appointment->patient?->user?->name ?? 'A patient';
            $this->notificationService->notify(
                $appointment->doctor->user,
                'appointment_confirmed',
                'Paid consultation confirmed',
                "{$patientName} completed payment and confirmed an appointment on {$appointment->appointment_date} at {$appointment->appointment_time}.",
                [
                    'appointment_id' => $appointment->id,
                    'patient_id' => $appointment->patient_id,
                    'patient_name' => $patientName,
                    'doctor_id' => $appointment->doctor_id,
                    'appointment_date' => $appointment->appointment_date,
                    'appointment_time' => $appointment->appointment_time,
                    'amount' => $appointment->amount,
                    'severity' => 'info',
                    'source' => 'appointment_payment',
                ],
                '/appointments/' . $appointment->id,
                true
            );
        }

        if ($appointment->patient?->user) {
            $this->notificationService->notify(
                $appointment->patient->user,
                'payment_success',
                'Payment successful',
                'Your appointment payment was successful and your consultation is confirmed.',
                [
                    'appointment_id' => $appointment->id,
                    'doctor_id' => $appointment->doctor_id,
                    'doctor_name' => $appointment->doctor?->user?->name,
                    'appointment_date' => $appointment->appointment_date,
                    'appointment_time' => $appointment->appointment_time,
                    'amount' => $appointment->amount,
                    'severity' => 'success',
                    'source' => 'appointment_payment',
                ],
                '/appointments/' . $appointment->id,
                true
            );
        }

        return $this->success($appointment->fresh(), 'Payment processed successfully');
    }

    public function failPayment(Request $request, Appointment $appointment)
    {
        if (!$this->canPay($request, $appointment)) {
            return $this->error('Only the patient who owns this appointment can update its payment.', 403);
        }
        if ($appointment->payment_status === 'paid') {
            return $this->error('Paid appointments cannot be marked as failed.', 409);
        }

        $appointment->update([
            'payment_status' => 'failed',
            'status' => 'pending_payment',
        ]);

        return $this->success($appointment->fresh(), 'Payment failed. Please try again.');
    }

    public function paymentStatus(Request $request, Appointment $appointment)
    {
        $this->authorizeAppointment($request, $appointment);

        return $this->success([
            'appointment_id' => $appointment->id,
            'payment_status' => $appointment->payment_status,
            'status' => $appointment->status,
            'amount' => $appointment->amount,
        ]);
    }

    private function canPay(Request $request, Appointment $appointment): bool
    {
        $user = $request->user();

        return ($user->role === 'patient' && $user->patient?->id === $appointment->patient_id)
            || $user->role === 'admin';
    }

    private function updateStatus(Appointment $appointment, string $status)
    {
        $appointment->update(['status' => $status]);
        return $this->success($appointment->fresh(), 'Appointment status updated successfully');
    }

    private function authorizeAppointment(Request $request, Appointment $appointment): void
    {
        $user = $request->user();

        abort_unless(
            $user->role === 'admin'
            || ($user->role === 'patient' && $user->patient?->id === $appointment->patient_id)
            || ($user->role === 'doctor' && $user->doctor?->id === $appointment->doctor_id),
            403
        );
    }
}
