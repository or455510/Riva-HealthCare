<?php

namespace App\Http\Controllers;

use App\Models\Medicine;
use App\Models\Pharmacy;
use App\Models\PharmacyMedicineAvailability;
use Illuminate\Http\Request;

/**
 * RIVA-110 (directive §16): public, read-only pharmacy directory + medicine
 * availability search — the actual product capability this foundation
 * exists for ("a patient can determine whether a medicine is available
 * through connected pharmacies/branches"). Every value returned here is
 * exactly what a pharmacy/admin entered through Admin\PharmacyAdminController
 * — nothing here computes, guesses, or defaults availability/price/stock.
 */
class PharmacyController extends Controller
{
    public function index()
    {
        $pharmacies = Pharmacy::where('is_active', true)
            ->with(['branches' => fn ($q) => $q->where('is_active', true)])
            ->orderBy('name')
            ->get();

        return $this->success($pharmacies);
    }

    public function show(Pharmacy $pharmacy)
    {
        abort_unless($pharmacy->is_active, 404);

        $pharmacy->load(['branches' => fn ($q) => $q->where('is_active', true)]);

        return $this->success($pharmacy);
    }

    public function branches(Pharmacy $pharmacy)
    {
        return $this->success(
            $pharmacy->branches()->where('is_active', true)->orderBy('name')->get()
        );
    }

    /**
     * Medicine catalog search — e.g. an autocomplete field for "which
     * medicine am I looking for" before checking availability.
     */
    public function medicines(Request $request)
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
        ]);

        $query = Medicine::where('is_active', true);

        if (! empty($data['search'])) {
            $term = '%'.$data['search'].'%';
            $query->where(function ($q) use ($term) {
                $q->where('name', 'like', $term)->orWhere('generic_name', 'like', $term);
            });
        }

        return $this->success($query->orderBy('name')->limit(50)->get());
    }

    /**
     * "Is this medicine available, and where?" — the core patient-facing
     * pharmacy query. Only returns branches with an explicit, admin-entered
     * availability row; a medicine/branch pair with no row at all is
     * indistinguishable from "unknown," which is correct — it must never
     * be presented as "confirmed unavailable" or "confirmed available."
     */
    public function availability(Medicine $medicine)
    {
        $availability = PharmacyMedicineAvailability::where('medicine_id', $medicine->id)
            ->where('is_available', true)
            ->whereHas('branch', fn ($q) => $q->where('is_active', true))
            ->with(['branch.pharmacy'])
            ->get()
            ->map(fn (PharmacyMedicineAvailability $row) => [
                'branch_id' => $row->branch->id,
                'branch_name' => $row->branch->name,
                'pharmacy_name' => $row->branch->pharmacy?->name,
                'address' => $row->branch->address,
                'city' => $row->branch->city,
                'phone' => $row->branch->phone,
                'price' => $row->price,
                'reported_at' => $row->reported_at,
            ]);

        return $this->success([
            'medicine' => $medicine,
            'available_at' => $availability,
        ]);
    }
}
