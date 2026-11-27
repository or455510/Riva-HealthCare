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

/**
 * SECURITY (Phase 0 / D5): this controller and Admin\AdminAreaController overlap —
 * both expose "list users", "update user status", and "review a doctor's
 * verification", under different route paths (see routes/api.php's admin group),
 * with AdminAreaController being the newer, richer implementation (pagination,
 * search, a self-deactivation guard on updateUserStatus, and fuller field updates
 * on doctor-verification approve/reject).
 *
 * DECISION: a full physical merge (one controller, one set of routes) was NOT done
 * in this pass. Reason: the two controllers return different response shapes for
 * overlapping actions (this one returns a plain array from users(), AdminArea
 * returns a paginator; the frontend Angular admin module was not staged/verified
 * in this pass, so changing response shapes or removing routes it may depend on
 * risks a real regression that would not be caught by the backend test suite
 * alone). Merging routes without frontend verification would violate the
 * "do not destroy existing working behavior without a deliberate architectural
 * reason" constraint.
 *
 * WHAT WAS ACTUALLY FIXED: the two places where the duplication had caused a real,
 * security-relevant behavioral gap between the two implementations —
 * updateUserStatus() (this legacy endpoint did not block admin self-deactivation)
 * and verifyDoctor() (this legacy endpoint did not set verified_at/is_verified/
 * clear rejection_reason the way the richer approve/reject actions do, so a doctor
 * verified through this route could end up in an inconsistent state). Both now
 * apply the same field updates as their Admin\AdminAreaController counterparts,
 * keeping this endpoint's existing input contract unchanged so no frontend caller
 * breaks. Full route consolidation is queued as a Phase 1 follow-up once the
 * Angular admin module can be updated and tested in the same pass.
 */
class AdminController extends Controller
{
    public function users()
    {
        return $this->success(User::latest()->get());
    }

    public function patients()
    {
        return $this->success(Patient::with('user')->latest()->get());
    }

    public function doctors()
    {
        return $this->success(Doctor::with('user')->latest()->get());
    }

    public function caregivers()
    {
        return $this->success(Caregiver::with('user')->latest()->get());
    }

    public function reports()
    {
        return $this->success(Report::with(['patient.user', 'doctor.user'])->latest()->get());
    }

    public function alerts()
    {
        return $this->success(Notification::latest()->get());
    }

    public function activityLogs()
    {
        return $this->success(SystemActivityLog::latest('created_at')->get());
    }

    public function loginAttempts()
    {
        return $this->success(LoginAttempt::latest('created_at')->get());
    }

    public function updateUserStatus(Request $request, User $user)
    {
        $data = $request->validate(['is_active' => ['required', 'boolean']]);

        if ($request->user()->id === $user->id && $data['is_active'] === false) {
            return $this->error('Admin users cannot deactivate or suspend themselves.', 422);
        }

        $user->update([
            'is_active' => $data['is_active'],
            'status' => $data['is_active'] ? 'active' : 'inactive',
        ]);

        return $this->success($user->fresh(), 'User status updated successfully');
    }

    public function verifyDoctor(Request $request, Doctor $doctor)
    {
        $data = $request->validate(['verification_status' => ['required', 'in:pending,verified,rejected']]);

        $doctor->update(match ($data['verification_status']) {
            'verified' => [
                'verification_status' => 'verified',
                'verified_at' => now(),
                'rejection_reason' => null,
                'is_verified' => true,
            ],
            'rejected' => [
                'verification_status' => 'rejected',
                'verified_at' => null,
                // This legacy endpoint's input contract has no "reason" field (unlike
                // Admin\AdminAreaController::rejectDoctor()); preserve whatever reason
                // was already on record rather than silently discarding it.
                'rejection_reason' => $doctor->rejection_reason,
                'is_verified' => false,
            ],
            default => [
                'verification_status' => 'pending',
                'verified_at' => null,
                'is_verified' => false,
            ],
        });

        return $this->success($doctor->fresh('user'), 'Doctor verification updated successfully');
    }
}
