<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Medicine;
use App\Models\Pharmacy;
use App\Models\PharmacyBranch;
use App\Models\PharmacyMedicineAvailability;
use Illuminate\Http\Request;

/**
 * RIVA-110 (directive §16): admin-only write access for the pharmacy
 * foundation. This is the ONLY place any of these four tables can be
 * written — PharmacyController (public) is strictly read-only. Every
 * availability row created/updated here is explicit, admin-entered data;
 * nothing is inferred, defaulted to "available," or backfilled.
 */
class PharmacyAdminController extends Controller
{
    // ---- Pharmacies -----------------------------------------------------

    public function indexPharmacies()
    {
        return $this->success(Pharmacy::withCount('branches')->latest()->get());
    }

    public function storePharmacy(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'website' => ['nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $pharmacy = Pharmacy::create([...$data, 'is_active' => $data['is_active'] ?? true]);

        return $this->success($pharmacy, 'Pharmacy created successfully', 201);
    }

    public function updatePharmacy(Request $request, Pharmacy $pharmacy)
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'website' => ['nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $pharmacy->update($data);

        return $this->success($pharmacy->fresh(), 'Pharmacy updated successfully');
    }

    public function destroyPharmacy(Pharmacy $pharmacy)
    {
        $pharmacy->delete();

        return $this->success(null, 'Pharmacy deleted successfully');
    }

    // ---- Branches ---------------------------------------------------------

    public function storeBranch(Request $request, Pharmacy $pharmacy)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'address' => ['required', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'is_24_hours' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $branch = $pharmacy->branches()->create([
            ...$data,
            'is_24_hours' => $data['is_24_hours'] ?? false,
            'is_active' => $data['is_active'] ?? true,
        ]);

        return $this->success($branch, 'Branch created successfully', 201);
    }

    public function updateBranch(Request $request, PharmacyBranch $branch)
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'is_24_hours' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $branch->update($data);

        return $this->success($branch->fresh(), 'Branch updated successfully');
    }

    public function destroyBranch(PharmacyBranch $branch)
    {
        $branch->delete();

        return $this->success(null, 'Branch deleted successfully');
    }

    // ---- Medicine catalog -------------------------------------------------

    public function indexMedicines()
    {
        return $this->success(Medicine::latest()->get());
    }

    public function storeMedicine(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'generic_name' => ['nullable', 'string', 'max:255'],
            'form' => ['nullable', 'string', 'max:100'],
            'strength' => ['nullable', 'string', 'max:100'],
            'requires_prescription' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $medicine = Medicine::create([
            ...$data,
            'requires_prescription' => $data['requires_prescription'] ?? false,
            'is_active' => $data['is_active'] ?? true,
        ]);

        return $this->success($medicine, 'Medicine created successfully', 201);
    }

    public function updateMedicine(Request $request, Medicine $medicine)
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'generic_name' => ['nullable', 'string', 'max:255'],
            'form' => ['nullable', 'string', 'max:100'],
            'strength' => ['nullable', 'string', 'max:100'],
            'requires_prescription' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $medicine->update($data);

        return $this->success($medicine->fresh(), 'Medicine updated successfully');
    }

    public function destroyMedicine(Medicine $medicine)
    {
        $medicine->delete();

        return $this->success(null, 'Medicine deleted successfully');
    }

    // ---- Availability -------------------------------------------------------

    /**
     * Create-or-update-in-place: one row per (branch, medicine) is enforced
     * by the migration's unique constraint, so this always resolves to a
     * single authoritative row per pair rather than allowing duplicates.
     */
    public function storeAvailability(Request $request)
    {
        $data = $request->validate([
            'pharmacy_branch_id' => ['required', 'integer', 'exists:pharmacy_branches,id'],
            'medicine_id' => ['required', 'integer', 'exists:medicines,id'],
            'is_available' => ['required', 'boolean'],
            'price' => ['nullable', 'numeric', 'min:0'],
        ]);

        $availability = PharmacyMedicineAvailability::updateOrCreate(
            [
                'pharmacy_branch_id' => $data['pharmacy_branch_id'],
                'medicine_id' => $data['medicine_id'],
            ],
            [
                'is_available' => $data['is_available'],
                'price' => $data['price'] ?? null,
                'reported_at' => now(),
            ]
        );

        return $this->success($availability->fresh(), 'Availability recorded successfully', 201);
    }

    public function updateAvailability(Request $request, PharmacyMedicineAvailability $availability)
    {
        $data = $request->validate([
            'is_available' => ['sometimes', 'boolean'],
            'price' => ['nullable', 'numeric', 'min:0'],
        ]);

        $availability->update([...$data, 'reported_at' => now()]);

        return $this->success($availability->fresh(), 'Availability updated successfully');
    }

    public function destroyAvailability(PharmacyMedicineAvailability $availability)
    {
        $availability->delete();

        return $this->success(null, 'Availability record deleted successfully');
    }
}
