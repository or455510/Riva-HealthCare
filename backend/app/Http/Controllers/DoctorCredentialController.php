<?php

namespace App\Http\Controllers;

use App\Models\Doctor;
use App\Models\DoctorCredential;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * RIVA audit (Section 2 — Doctor public credentials/certificates,
 * 2026-08-28): lets a doctor list professional credentials (degrees,
 * certificates, training) and choose which are publicly visible on their
 * profile — architecturally separate from DoctorVerificationController,
 * which handles PRIVATE verification documents only (Rule 4: never expose
 * those publicly). index()/store()/update()/destroy() are doctor-only and
 * owner-scoped; forDoctor() is the public, unauthenticated read used by the
 * Doctor profile page, mirroring the existing visibility level of
 * DoctorController::services() and PaymentMethodController::forDoctor() —
 * and, like those, only ever returns rows the doctor has actively opted
 * in to showing (is_public = true).
 */
class DoctorCredentialController extends Controller
{
    public function index(Request $request)
    {
        $doctor = $this->requireDoctor($request);

        return $this->success(
            $doctor->credentials()->orderByDesc('year')->orderByDesc('created_at')->get()
        );
    }

    public function store(Request $request)
    {
        $doctor = $this->requireDoctor($request);
        $data = $this->validated($request);

        if ($request->hasFile('file')) {
            $data['file_path'] = $request->file('file')->store('doctor-credentials', 'public');
        }

        $credential = DoctorCredential::create([
            ...$data,
            'doctor_id' => $doctor->id,
        ]);

        return $this->success($credential->fresh(), 'Credential added successfully', 201);
    }

    public function update(Request $request, DoctorCredential $credential)
    {
        $doctor = $this->requireDoctor($request);
        $this->assertOwnedByDoctor($credential, $doctor);

        $data = $this->validated($request, partial: true);

        if ($request->hasFile('file')) {
            if ($credential->file_path) {
                Storage::disk('public')->delete($credential->file_path);
            }
            $data['file_path'] = $request->file('file')->store('doctor-credentials', 'public');
        }

        $credential->update($data);

        return $this->success($credential->fresh(), 'Credential updated successfully');
    }

    public function destroy(Request $request, DoctorCredential $credential)
    {
        $doctor = $this->requireDoctor($request);
        $this->assertOwnedByDoctor($credential, $doctor);

        if ($credential->file_path) {
            Storage::disk('public')->delete($credential->file_path);
        }

        $credential->delete();

        return $this->success(null, 'Credential removed successfully');
    }

    /**
     * Public — only credentials this doctor has actively chosen to display.
     * Never returns a row where is_public is false, regardless of what
     * fields the row itself carries.
     */
    public function forDoctor(Doctor $doctor)
    {
        $credentials = $doctor->credentials()
            ->where('is_public', true)
            ->orderByDesc('year')
            ->orderByDesc('created_at')
            ->get(['id', 'doctor_id', 'title', 'credential_type', 'issuing_organization', 'year', 'description', 'file_path', 'is_public']);

        return $this->success($credentials);
    }

    private function requireDoctor(Request $request): Doctor
    {
        $doctor = $request->user()->doctor;
        abort_unless($doctor, 403);

        return $doctor;
    }

    private function assertOwnedByDoctor(DoctorCredential $credential, Doctor $doctor): void
    {
        abort_unless($credential->doctor_id === $doctor->id, 404);
    }

    private function validated(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'title' => [$required, 'string', 'max:255'],
            'credential_type' => [$required, 'string', Rule::in([
                'bachelor', 'master', 'phd', 'certificate', 'training', 'professional_license', 'other',
            ])],
            'issuing_organization' => ['nullable', 'string', 'max:255'],
            'year' => ['nullable', 'integer', 'min:1950', 'max:'.(date('Y') + 1)],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_public' => ['nullable', 'boolean'],
            'file' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
        ]);
    }
}
