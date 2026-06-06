<?php

namespace App\Http\Controllers;

use App\Models\Caregiver;
use App\Models\Doctor;
use App\Models\Patient;
use App\Services\ActivityLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class ProfileController extends Controller
{
    public function __construct(private readonly ActivityLogService $activityLogService)
    {
    }

    public function show(Request $request)
    {
        $user = $request->user()->load(['patient', 'doctor', 'caregiver']);
        $roleProfile = $user->patient ?? $user->doctor ?? $user->caregiver;

        $user->append('profile_image_url');

        return response()->json([
            'user' => $user,
            'role' => $user->role,
            'role_profile' => $roleProfile,
            'profile_completed' => (bool) $user->profile_completed_at,
        ]);
    }

    public function update(Request $request)
    {
        return $this->saveProfile($request, false);
    }

    private function saveProfile(Request $request, bool $markComplete)
    {
        DB::beginTransaction();

        try {
            $user = $request->user();

            // ✅ معالجة صورة البروفايل
            if ($request->hasFile('profile_image')) {
                $request->validate([
                    'profile_image' => ['image', 'mimes:jpeg,png,jpg,gif,webp', 'max:2048'],
                ]);
                $this->updateProfileImage($request, $user);
            }

            // ✅ تحديث البيانات العامة
            $userData = $request->validate([
                'first_name' => ['nullable', 'string', 'max:255'],
                'last_name' => ['nullable', 'string', 'max:255'],
                'phone' => ['nullable', 'string', 'max:50'],
                'address' => ['nullable', 'string', 'max:255'],
            ]);

            if (count(array_filter($userData, fn ($value) => $value !== null)) > 0) {
                $user->fill($userData);
                $user->name = trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? ''));
                $user->save();
            }

            // ✅ تحديث بيانات الـ Role
            $roleProfile = match ($user->role) {
                'doctor' => $this->updateDoctorProfile($request, $user->doctor ?? Doctor::create(['user_id' => $user->id])),
                'caregiver' => $this->updateCaregiverProfile($request, $user->caregiver ?? Caregiver::create(['user_id' => $user->id])),
                default => $this->updatePatientProfile($request, $user->patient ?? Patient::create(['user_id' => $user->id])),
            };

            if ($markComplete && !$user->profile_completed_at) {
                $user->forceFill(['profile_completed_at' => now()])->save();
            }

            $this->activityLogService->log($user, 'profile.updated', 'Profile updated', $request);

            DB::commit();

            $freshUser = $user->fresh(['patient', 'doctor', 'caregiver']);
            $freshUser->append('profile_image_url');

            return response()->json([
                'message' => 'Profile updated successfully',
                'data' => [
                    'user' => $freshUser,
                    'role' => $freshUser->role,
                    'role_profile' => $roleProfile->fresh(),
                    'profile_completed' => (bool) $freshUser->profile_completed_at,
                ]
            ], 200);

        } catch (\Illuminate\Validation\ValidationException $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Profile update failed', [
                'user_id' => $request->user()?->id,
                'error' => $e->getMessage()
            ]);
            return response()->json([
                'message' => 'Failed to update profile'
            ], 500);
        }
    }

    public function complete(Request $request)
    {
        if (app()->environment('local')) {
            Log::info('Profile complete - Files:', [
                'files' => $request->allFiles(),
                'has_profile_image' => $request->hasFile('profile_image')
            ]);
        }

        // معالجة الصورة مباشرة ثم استدعاء update
        if ($request->hasFile('profile_image')) {
            $request->validate([
                'profile_image' => ['image', 'mimes:jpeg,png,jpg,gif', 'max:2048'],
            ]);

            // The main update flow handles the upload once inside the transaction.
        }

        // استدعاء update بدون معالجة الصورة مرة أخرى
        return $this->saveProfile($request, true);
    }

    public function changePassword(Request $request)
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'new_password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user = $request->user();

        if (!Hash::check($data['current_password'], $user->password)) {
            return response()->json([
                'message' => 'Current password is incorrect.'
            ], 422);
        }

        $user->update(['password' => $data['new_password']]);

        $this->activityLogService->log($user, 'password.changed', 'Password changed', $request);

        return response()->json([
            'message' => 'Password updated successfully'
        ], 200);
    }

    // ...existing methods...

    private function updateProfileImage(Request $request, $user): void
    {
        if ($user->profile_image && Storage::disk('public')->exists($user->profile_image)) {
            Storage::disk('public')->delete($user->profile_image);
        }
        if ($user->profile_image && File::exists(public_path('storage/' . $user->profile_image))) {
            File::delete(public_path('storage/' . $user->profile_image));
        }

        $path = $request->file('profile_image')->store('profile_images', 'public');
        $sourcePath = Storage::disk('public')->path($path);
        $publicPath = public_path('storage/' . $path);

        if (!File::exists($publicPath) && File::exists($sourcePath)) {
            File::ensureDirectoryExists(dirname($publicPath));
            File::copy($sourcePath, $publicPath);
        }

        $user->update(['profile_image' => $path]);
    }

    private function updatePatientProfile(Request $request, Patient $patient): Patient
    {
        $data = $request->validate([
            'gender' => ['nullable', 'string', 'max:50'],
            'date_of_birth' => ['nullable', 'date'],
            'age' => ['nullable', 'integer', 'min:0', 'max:120'],
            'blood_type' => ['nullable', 'string', 'max:20'],
            'emergency_contact' => ['nullable', 'string', 'max:255'],
            'chronic_conditions' => ['nullable', 'string'],
            'medical_history' => ['nullable', 'string'],
            'about' => ['nullable', 'string'],
        ]);

        $patient->fill(array_filter($data, fn($value) => $value !== null))->save();

        return $patient->fresh();
    }

    private function updateDoctorProfile(Request $request, Doctor $doctor): Doctor
    {
        $data = $request->validate([
            'specialty' => ['nullable', 'string', 'max:255'],
            'years_of_experience' => ['nullable', 'integer', 'min:0'],
            'experience_years' => ['nullable', 'integer', 'min:0'],
            'fee' => ['nullable', 'numeric', 'min:0'],
            'bio' => ['nullable', 'string'],
            'about' => ['nullable', 'string'],
            'license_number' => ['nullable', 'string', 'max:255'],
            'contact_info' => ['nullable', 'string', 'max:255'],
            'available_days' => ['nullable', 'string', 'max:255'],
        ]);

        $experienceYears = $data['years_of_experience'] ?? $data['experience_years'];

        $doctor->fill([
            'specialty' => $data['specialty'] ?? $doctor->specialty,
            'years_of_experience' => $experienceYears ?? $doctor->years_of_experience,
            'fee' => $data['fee'] ?? $doctor->fee,
            'bio' => $data['bio'] ?? $doctor->bio,
            'about' => $data['about'] ?? $doctor->about,
            'license_number' => $data['license_number'] ?? $doctor->license_number,
            'contact_info' => $data['contact_info'] ?? $doctor->contact_info,
            'available_days' => $data['available_days'] ?? $doctor->available_days,
        ])->save();

        return $doctor->fresh();
    }

    private function updateCaregiverProfile(Request $request, Caregiver $caregiver): Caregiver
    {
        $data = $request->validate([
            'specialty' => ['nullable', 'string', 'max:255'],
            'experience_years' => ['nullable', 'integer', 'min:0'],
            'salary' => ['nullable', 'numeric', 'min:0'],
            'bio' => ['nullable', 'string'],
            'about' => ['nullable', 'string'],
        ]);

        $caregiver->fill([
            'specialty' => $data['specialty'] ?? $caregiver->specialty,
            'experience_years' => $data['experience_years'] ?? $caregiver->experience_years,
            'salary' => $data['salary'] ?? $caregiver->salary,
            'about' => $data['about'] ?? $data['bio'] ?? $caregiver->about,
        ])->save();

        return $caregiver->fresh();
    }
}
