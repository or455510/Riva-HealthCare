<?php

namespace App\Http\Controllers;

use App\Models\Doctor;
use App\Models\DoctorPaymentMethod;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * RIVA-130 follow-up (product-owner request, recorded 2026-08-25): "the
 * payment page looks unprofessional with no real payment details... let the
 * DOCTOR be the one who chooses which payment method(s) they personally
 * accept, based on what's actually available to them — never a fixed set
 * shown to every patient."
 *
 * CATALOG below is the fixed, real list of payment rails actually in common
 * use in Egypt (three mobile-wallet operators, InstaPay, card, and cash at
 * the clinic) — this is honest domain knowledge, not a fabricated taxonomy.
 * A doctor picks which of these they personally accept and, for the ones
 * that need one, supplies the real account detail (phone number / handle)
 * patients should send payment to. There is no payment gateway anywhere in
 * this codebase (see PaymentGatewayInterface's docblock) — this never
 * processes a payment, it only lets the doctor tell patients HOW to pay
 * them, replacing the old one-size-fits-all "pay at the clinic" text with
 * real, doctor-specific instructions.
 */
class PaymentMethodController extends Controller
{
    public const CATALOG = [
        ['key' => 'vodafone_cash', 'label' => 'Vodafone Cash', 'requires_account_label' => true, 'account_label_hint' => 'Vodafone Cash phone number'],
        ['key' => 'orange_cash', 'label' => 'Orange Cash', 'requires_account_label' => true, 'account_label_hint' => 'Orange Cash phone number'],
        ['key' => 'we_pay', 'label' => 'WE Pay', 'requires_account_label' => true, 'account_label_hint' => 'WE Pay phone number'],
        ['key' => 'instapay', 'label' => 'InstaPay', 'requires_account_label' => true, 'account_label_hint' => 'InstaPay mobile number or @handle'],
        ['key' => 'card', 'label' => 'Visa / Mastercard', 'requires_account_label' => false, 'account_label_hint' => null],
        ['key' => 'cash', 'label' => 'Cash at the clinic', 'requires_account_label' => false, 'account_label_hint' => null],
    ];

    /**
     * The fixed catalog of payment rails the app knows about — public (any
     * signed-in user), used to render the doctor's own settings form.
     */
    public function catalog()
    {
        return $this->success(self::CATALOG);
    }

    /**
     * The authenticated doctor's own configured methods, including inactive
     * ones — the settings page needs to show what's toggled off too.
     */
    public function index(Request $request)
    {
        $doctor = $this->requireDoctor($request);

        return $this->success(
            $doctor->paymentMethods()->orderBy('method')->get()->map(fn (DoctorPaymentMethod $m) => $this->serialize($m))
        );
    }

    /**
     * Add or update this doctor's own configuration for one payment method
     * (upsert on doctor_id+method, matching the table's unique constraint) —
     * a settings form naturally re-saves the same method when the doctor
     * edits their account detail rather than deleting and re-adding it.
     */
    public function store(Request $request)
    {
        $doctor = $this->requireDoctor($request);
        $data = $this->validated($request);

        $requiresLabel = collect(self::CATALOG)->firstWhere('key', $data['method'])['requires_account_label'];
        if ($requiresLabel && blank($data['account_label'] ?? null)) {
            return $this->error('This payment method needs an account number/handle for patients to pay to.', 422);
        }

        $method = DoctorPaymentMethod::updateOrCreate(
            ['doctor_id' => $doctor->id, 'method' => $data['method']],
            [
                'account_label' => $data['account_label'] ?? null,
                'is_active' => $data['is_active'] ?? true,
            ]
        );

        return $this->success($this->serialize($method), 'Payment method saved.', 201);
    }

    public function update(Request $request, DoctorPaymentMethod $paymentMethod)
    {
        $doctor = $this->requireDoctor($request);
        $this->assertOwnedByDoctor($paymentMethod, $doctor);

        $data = $request->validate([
            'account_label' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $requiresLabel = collect(self::CATALOG)->firstWhere('key', $paymentMethod->method)['requires_account_label'];
        $nextLabel = array_key_exists('account_label', $data) ? $data['account_label'] : $paymentMethod->account_label;
        $nextActive = $data['is_active'] ?? $paymentMethod->is_active;
        if ($requiresLabel && $nextActive && blank($nextLabel)) {
            return $this->error('This payment method needs an account number/handle for patients to pay to.', 422);
        }

        $paymentMethod->update($data);

        return $this->success($this->serialize($paymentMethod->fresh()), 'Payment method updated.');
    }

    public function destroy(Request $request, DoctorPaymentMethod $paymentMethod)
    {
        $doctor = $this->requireDoctor($request);
        $this->assertOwnedByDoctor($paymentMethod, $doctor);

        $paymentMethod->delete();

        return $this->success(null, 'Payment method removed.');
    }

    /**
     * Public — the same visibility level as GET /doctors/{doctor}/services —
     * only the methods this doctor has actively chosen to expose, for the
     * patient-facing booking/payment page.
     */
    public function forDoctor(Doctor $doctor)
    {
        $methods = $doctor->paymentMethods()
            ->where('is_active', true)
            ->orderBy('method')
            ->get()
            ->map(fn (DoctorPaymentMethod $m) => $this->serialize($m));

        return $this->success($methods);
    }

    private function requireDoctor(Request $request): Doctor
    {
        $doctor = $request->user()->doctor;
        abort_unless($doctor, 403);

        return $doctor;
    }

    private function assertOwnedByDoctor(DoctorPaymentMethod $method, Doctor $doctor): void
    {
        abort_unless($method->doctor_id === $doctor->id, 404);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'method' => ['required', 'string', Rule::in(array_column(self::CATALOG, 'key'))],
            'account_label' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
        ]);
    }

    private function serialize(DoctorPaymentMethod $method): array
    {
        $catalogEntry = collect(self::CATALOG)->firstWhere('key', $method->method);

        return [
            'id' => $method->id,
            'method' => $method->method,
            'label' => $catalogEntry['label'] ?? $method->method,
            'account_label' => $method->account_label,
            'is_active' => $method->is_active,
        ];
    }
}
