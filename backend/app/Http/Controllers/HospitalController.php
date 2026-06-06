<?php

namespace App\Http\Controllers;

use App\Models\Doctor;
use App\Models\Hospital;
use Illuminate\Http\Request;

class HospitalController extends Controller
{
    public function index()
    {
        return $this->success(Hospital::latest()->get());
    }

    public function show(Hospital $hospital)
    {
        return $this->success($hospital);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
        ]);

        return $this->success(Hospital::create($data), 'Hospital created successfully', 201);
    }

    public function update(Request $request, Hospital $hospital)
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
        ]);

        $hospital->update($data);
        return $this->success($hospital->fresh(), 'Hospital updated successfully');
    }

    public function destroy(Hospital $hospital)
    {
        $hospital->delete();
        return $this->success(null, 'Hospital deleted successfully');
    }

    public function attachDoctorHospital(Request $request)
    {
        $data = $request->validate([
            'doctor_id' => ['required', 'exists:doctors,id'],
            'hospital_id' => ['required', 'exists:hospitals,id'],
        ]);

        $doctor = Doctor::findOrFail($data['doctor_id']);
        $doctor->hospitals()->syncWithoutDetaching([$data['hospital_id']]);

        return $this->success($doctor->hospitals()->get(), 'Doctor hospital assigned successfully');
    }
}
