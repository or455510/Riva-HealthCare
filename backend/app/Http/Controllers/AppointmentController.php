<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\AppointmentBookingLog;
use App\Models\ClinicService;
use App\Models\ClinicUser;
use App\Models\Doctor;
use App\Models\PatientCaregiverRelationship;
use App\Models\PatientDoctorRelationship;
use App\Services\NotificationService;
use App\Services\Payments\PaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class AppointmentController extends Controller
{
    public function __construct(
        private readonly NotificationService $notificationService,
        private readonly PaymentService $paymentService,
    ) {}

    /**
     * SECURITY (Phase 1 Slice 2 / CRITICAL IDOR): this used to only filter for
     * role === 'patient' or role === 'doctor'; any other authenticated role that
     * reaches this endpoint — in practice 'caregiver', since the route group is
     * middleware('role:patient,doctor,caregiver,admin') — fell through with NO
     * where() applied at all, meaning $query->latest()->get() returned EVERY
     * appointment in the entire system: every patient's name, every doctor,
     * dates/times, notes, and payment amount/status, across every clinic. The
     * same silent fallthrough would also hit a 'doctor' or 'patient' account
     * whose Doctor/Patient profile row doesn't exist yet (edge case, but the
     * bug is identical). Every branch is now explicit, and anything that isn't
     * a recognized, fully-scoped case is denied a result set rather than
     * defaulting to "show everything" — admin is the only intentional
     * exception (platform-wide oversight, matches every other admin-only
     * unfiltered list in this codebase).
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $query = Appointment::with(['patient.user', 'doctor.user', 'hospital', 'service']);

        if ($user->role === 'admin') {
            // intentionally unfiltered: platform admin oversight.
        } elseif ($user->role === 'patient' && $user->patient) {
            $query->where('patient_id', $user->patient->id);
        } elseif ($user->role === 'doctor' && $user->doctor) {
            $query->where('doctor_id', $user->doctor->id);
        } elseif ($user->role === 'caregiver' && $user->caregiver) {
            $patientIds = PatientCaregiverRelationship::where('caregiver_id', $user->caregiver->id)
                ->whereIn('status', ['active', 'accepted'])
                ->pluck('patient_id');
            $query->whereIn('patient_id', $patientIds);
        } else {
            // Unrecognized role, or a role whose profile row is missing — never
            // fall through to an unfiltered query.
            $query->whereRaw('1 = 0');
        }

        return $this->success($query->latest()->get());
    }

    /**
     * SECURITY (Phase 0 / mass-assignment audit): 'amount' used to be accepted
     * directly from the client and stored as-is ($data['amount'] ?? 0) — a patient
     * booking their own appointment could set their own price (e.g. pay 0.01 for a
     * doctor whose real fee is 300), and nothing here or in pay()/update() ever
     * checked it against the doctor's actual fee. The price is now always derived
     * server-side from Doctor::fee; any 'amount' sent by the client is silently
     * ignored. See update() below for the matching fix on the edit path.
     *
     * Phase C (Services & Prices, principal-engineering directive): an
     * optional service_id lets the patient pick one of the doctor's clinic's
     * bookable, priced offerings instead of the doctor's flat fee. The same
     * client-trust rule applies — the client sends only an id, never a
     * price — and an invalid/inactive/wrong-clinic service_id is rejected
     * outright (422) rather than silently falling back to Doctor::fee, so a
     * patient can never end up paying an amount they didn't actually agree
     * to. Omitting service_id entirely is unchanged, existing behavior.
     */
    public function store(Request $request)
    {
        $patient = $request->user()->patient;
        abort_unless($patient, 403);

        $data = $request->validate([
            'doctor_id' => ['required', 'exists:doctors,id'],
            'hospital_id' => ['nullable', 'exists:hospitals,id'],
            'service_id' => ['nullable', 'exists:clinic_services,id'],
            'appointment_date' => ['required', 'date', 'after_or_equal:today'],
            'appointment_time' => ['required', 'date_format:H:i'],
            'type' => ['nullable', 'in:online,offline,inperson'],
            'notes' => ['nullable', 'string'],
        ]);

        $doctor = Doctor::with('user')->findOrFail($data['doctor_id']);

        $amount = $doctor->fee ?? 0;

        if (! empty($data['service_id'])) {
            $service = ClinicService::find($data['service_id']);

            if (! $service || ! $service->is_active) {
                return $this->error('The selected service is not available.', 422);
            }

            $doctorBelongsToServiceClinic = ClinicUser::where('user_id', $doctor->user_id)
                ->where('clinic_id', $service->clinic_id)
                ->where('status', 'active')
                ->exists();

            if (! $doctorBelongsToServiceClinic) {
                return $this->error('The selected service is not offered by this doctor.', 422);
            }

            $amount = $service->price;
        }

        // COST/CORRECTNESS (RIVA-116, P1 — double-booking): store() never
        // checked for an existing appointment at the same doctor/date/time
        // before creating a new one, and there's no DB-level unique
        // constraint on (doctor_id, appointment_date, appointment_time) —
        // adding one directly isn't safe either, since a cancelled
        // appointment must free the slot back up and MySQL has no portable
        // partial/filtered unique index. Cache::lock() (same atomic-lock
        // mechanism this codebase already uses for the scheduler's
        // withoutOverlapping()) serializes concurrent booking attempts for
        // this exact slot; the conflict check itself runs while holding the
        // lock, closing the check-then-insert race a plain query alone
        // would leave open.
        $slotLockKey = "appointment-slot:{$doctor->id}:{$data['appointment_date']}:{$data['appointment_time']}";
        $slotLock = Cache::lock($slotLockKey, 10);

        if (! $slotLock->get()) {
            return $this->error('This time slot is currently being booked by someone else — please try again in a moment.', 409);
        }

        try {
            $slotTaken = Appointment::where('doctor_id', $doctor->id)
                ->where('appointment_date', $data['appointment_date'])
                ->where('appointment_time', $data['appointment_time'])
                ->whereNotIn('status', ['cancelled'])
                ->exists();

            if ($slotTaken) {
                return $this->error('This doctor already has an appointment at the selected date and time. Please choose another slot.', 409);
            }

            $appointment = Appointment::create([
                ...$data,
                'patient_id' => $patient->id,
                'amount' => $amount,
                'status' => 'pending_payment',
                'payment_status' => 'unpaid',
            ]);
        } finally {
            $slotLock->release();
        }

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

        if (! $relationship) {
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
            '/appointments/book/'.$doctor->id,
            false
        );

        return $this->success($appointment, 'Appointment created successfully', 201);
    }

    public function show(Request $request, Appointment $appointment)
    {
        $this->authorizeAppointment($request, $appointment);

        return $this->success($appointment->load(['patient.user', 'doctor.user', 'hospital', 'service']));
    }

    public function update(Request $request, Appointment $appointment)
    {
        $this->authorizeAppointment($request, $appointment);
        $user = $request->user();

        $rules = [
            'appointment_date' => ['nullable', 'date'],
            'appointment_time' => ['nullable', 'date_format:H:i'],
            'type' => ['nullable', 'in:online,offline,inperson'],
            'notes' => ['nullable', 'string'],
        ];

        // SECURITY (Phase 0 / mass-assignment audit): 'amount' used to be accepted
        // here from any owning patient or doctor, with no gate at all — not even
        // admin-only, unlike 'status' below — so either party could silently change
        // the price of an appointment at any time via this generic endpoint,
        // including after an admin had already recorded it as paid, corrupting the
        // payment record's integrity. The price is fixed at booking time from
        // Doctor::fee (see store()) and is not editable through this endpoint by
        // anyone. A deliberate future price correction/negotiation flow, if needed,
        // should be its own explicit, audited admin action — not a silent field on
        // the generic update.

        // SECURITY (Phase 0): 'status' used to be accepted from any owning patient or
        // doctor via this generic update endpoint, including 'confirmed' and
        // 'completed' — the exact two states that confirm()/complete() are supposed
        // to gate behind payment_status === 'paid'. That let a patient bypass the
        // payment gate entirely by calling PUT /appointments/{id} with
        // {"status":"confirmed"} instead of paying. Only admins may set status
        // directly now; everyone else must go through confirm()/cancel()/complete()/
        // pay(), which each enforce their own rules.
        if ($user->role === 'admin') {
            $rules['status'] = ['nullable', 'in:pending_payment,pending,confirmed,cancelled,completed'];
        }

        $data = $request->validate($rules);

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

    public function cancel(Request $request, Appointment $appointment)
    {
        $this->authorizeAppointment($request, $appointment);

        return $this->updateStatus($appointment, 'cancelled');
    }

    public function complete(Request $request, Appointment $appointment)
    {
        $this->authorizeAppointment($request, $appointment);
        if ($appointment->payment_status !== 'paid') {
            return $this->error('Payment must be completed before completing this appointment.', 422);
        }

        return $this->updateStatus($appointment, 'completed');
    }

    /**
     * SECURITY (Phase 0): this endpoint used to let the owning PATIENT flip their own
     * appointment to payment_status=paid with no gateway, no transaction record and no
     * verification of any kind — literally "the client says it paid, so it's paid".
     * That is the single most serious finding from the audit.
     *
     * There is no real payment gateway integration yet (that is Phase 6 of the
     * blueprint) and no clinic-staff/reception role yet either (Phase 2). Until one of
     * those exists, the only party in the current system authorized to attest that
     * money actually changed hands is a platform admin — acting as a manual stand-in
     * for "staff confirmed payment was received." This is a deliberate, temporary,
     * reversible restriction, not the final design: it intentionally trades away
     * patient self-service payment (nobody can pay for their own appointment through
     * the product right now) in exchange for closing the client-trust hole. Product
     * sign-off is needed on how appointments get paid in practice until Phase 6 or
     * Phase 2 lands — see PHASE0_SECURITY_CHANGELOG for the explicit flag.
     *
     * Payment architecture (principal-engineering directive, Slice 12): this used
     * to write straight to appointments.payment_status/status with no transaction
     * record at all — the admin's attestation was real (Phase 0 fixed WHO can
     * call this), but there was nothing to audit, retry, or refund against. Now
     * goes through PaymentService::charge(), which snapshots the amount, creates
     * a Payment row, calls the active PaymentGatewayInterface binding (today:
     * ManualPaymentGateway — see its docblock for why that's honest, not fake),
     * and only then updates the appointment. See routes/api.php's
     * GET .../payments for the resulting audit trail.
     */
    public function pay(Request $request, Appointment $appointment)
    {
        if (! $this->canRecordPayment($request, $appointment)) {
            return $this->error('You do not have permission to record a payment for this appointment.', 403);
        }

        if ($appointment->payment_status === 'paid') {
            return $this->error('This appointment is already paid.', 409);
        }

        if ($appointment->status === 'cancelled') {
            return $this->error('Cancelled appointments cannot be paid.', 422);
        }

        $payment = $this->paymentService->charge($appointment, $request->user());

        if ($payment->status !== 'succeeded') {
            return $this->error('Payment could not be processed: '.($payment->failure_reason ?? 'unknown gateway error'), 422);
        }

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
                '/appointments/'.$appointment->id,
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
                '/appointments/'.$appointment->id,
                true
            );
        }

        return $this->success($appointment->fresh(), 'Payment processed successfully');
    }

    public function failPayment(Request $request, Appointment $appointment)
    {
        if (! $this->canRecordPayment($request, $appointment)) {
            return $this->error('You do not have permission to update this appointment\'s payment.', 403);
        }
        if ($appointment->payment_status === 'paid') {
            return $this->error('Paid appointments cannot be marked as failed.', 409);
        }

        $this->paymentService->recordFailure($appointment, $request->user(), 'Marked failed by admin');

        return $this->success($appointment->fresh(), 'Payment failed. Please try again.');
    }

    public function paymentStatus(Request $request, Appointment $appointment)
    {
        $this->authorizeAppointment($request, $appointment);

        return $this->success([
            'appointment_id' => $appointment->id,
            'payment_status' => $appointment->payment_status,
            'status' => $appointment->status,
            'payments' => $appointment->payments()->latest()->get(),
            'amount' => $appointment->amount,
        ]);
    }

    /**
     * BUG FIX (Phase C, principal-engineering directive — discovered while
     * building the Queue slice, fixed immediately per the "fix it now" rule):
     * this used to hardcode role === 'admin' only, completely ignoring the
     * ClinicUser::PERMISSION_RECORD_PAYMENTS permission Slice 3 built — a
     * clinic staff member granted that permission still could not record a
     * single payment, because nothing here ever checked it. This grants the
     * same capability to any user with an ACTIVE clinic membership, in a
     * clinic the appointment's doctor also actively belongs to, whose
     * membership carries payments.record (owner/admin always do, per
     * ClinicUser::hasPermission()). A stranger with no shared clinic still
     * gets no access, matching the existing cross-clinic isolation model.
     */
    private function canRecordPayment(Request $request, Appointment $appointment): bool
    {
        $user = $request->user();

        if ($user->role === 'admin') {
            return true;
        }

        $doctorUserId = $appointment->doctor?->user_id;
        if (! $doctorUserId) {
            return false;
        }

        $sharedActiveClinicIds = ClinicUser::where('user_id', $doctorUserId)
            ->where('status', 'active')
            ->pluck('clinic_id');

        if ($sharedActiveClinicIds->isEmpty()) {
            return false;
        }

        $membership = ClinicUser::where('user_id', $user->id)
            ->where('status', 'active')
            ->whereIn('clinic_id', $sharedActiveClinicIds)
            ->first();

        return $membership?->hasPermission(ClinicUser::PERMISSION_RECORD_PAYMENTS) ?? false;
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
