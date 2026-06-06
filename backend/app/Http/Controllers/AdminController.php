<?php

namespace App\Http\Controllers;

use App\Models\Caregiver;
use App\Models\Doctor;
use App\Models\LoginAttempt;
use App\Models\Notification;
use App\Models\Patient;
use App\Models\Report;
use App\Models\SystemActivityLog;
use App\Models\User;
use Illuminate\Http\Request;

class AdminController extends Controller
{
    public function users() { return $this->success(User::latest()->get()); }
    public function patients() { return $this->success(Patient::with('user')->latest()->get()); }
    public function doctors() { return $this->success(Doctor::with('user')->latest()->get()); }
    public function caregivers() { return $this->success(Caregiver::with('user')->latest()->get()); }
    public function reports() { return $this->success(Report::with(['patient.user', 'doctor.user'])->latest()->get()); }
    public function alerts() { return $this->success(Notification::latest()->get()); }
    public function activityLogs() { return $this->success(SystemActivityLog::latest('created_at')->get()); }
    public function loginAttempts() { return $this->success(LoginAttempt::latest('created_at')->get()); }

    public function updateUserStatus(Request $request, User $user)
    {
        $data = $request->validate(['is_active' => ['required', 'boolean']]);
        $user->update(['is_active' => $data['is_active']]);
        return $this->success($user->fresh(), 'User status updated successfully');
    }

    public function verifyDoctor(Request $request, Doctor $doctor)
    {
        $data = $request->validate(['verification_status' => ['required', 'in:pending,verified,rejected']]);
        $doctor->update(['verification_status' => $data['verification_status']]);
        return $this->success($doctor->fresh('user'), 'Doctor verification updated successfully');
    }
}
