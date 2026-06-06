<?php

namespace App\Http\Controllers;

use App\Models\Caregiver;
use App\Models\Doctor;
use App\Models\LoginAttempt;
use App\Models\Patient;
use App\Models\User;
use App\Services\ActivityLogService;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Socialite\Facades\Socialite;

class AuthController extends Controller
{
    public function __construct(
        private readonly ActivityLogService $activityLogService,
        private readonly NotificationService $notificationService
    )
    {
    }

    public function register(Request $request)
    {
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:255'],
            'password' => ['required', 'confirmed', 'min:8'],
            'role' => ['required', 'in:patient,doctor,caregiver,admin'],
        ]);

        $user = User::create([
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'name' => trim($data['first_name'].' '.$data['last_name']),
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'address' => $data['address'] ?? null,
            'role' => $data['role'],
            'password' => $data['password'],
            'profile_completed_at' => null,
        ]);

        $this->createRoleProfile($user);
        $this->notificationService->notify($user, 'welcome', 'Welcome to RIVA', 'Your account has been created successfully.');

        $token = $user->createToken('auth-token')->plainTextToken;
        $this->activityLogService->log($user, 'auth.register', 'User registered', $request);

        return response()->json([
            'message' => 'Registration successful',
            'user' => $this->formatUser($user->fresh(['patient', 'doctor', 'caregiver'])),
            'token' => $token,
        ], 201);
    }

    public function login(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $data['email'])->first();
        $successful = $user && Hash::check($data['password'], $user->password);

        LoginAttempt::create([
            'email' => $data['email'],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'successful' => (bool) $successful,
            'reason' => $successful ? null : 'Invalid credentials',
            'created_at' => now(),
        ]);

        if (!$successful || !$user) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        if (!$user->is_active) {
            return $this->error('This account is inactive.', 403);
        }

        $user->forceFill(['last_login_at' => now()])->save();
        $token = $user->createToken('auth-token')->plainTextToken;
        $this->activityLogService->log($user, 'auth.login', 'User logged in', $request);
        $this->notificationService->notify($user, 'login_alert', 'Security login alert', 'A new login was detected on your account.');

        return response()->json([
            'message' => 'Login successful',
            'user' => $this->formatUser($user->fresh(['patient', 'doctor', 'caregiver'])),
            'token' => $token,
        ]);
    }

    public function me(Request $request)
    {
        return response()->json([
            'user' => $this->formatUser($request->user()->load(['patient', 'doctor', 'caregiver'])),
        ]);
    }

    public function logout(Request $request)
    {
        $user = $request->user();
        $request->user()->currentAccessToken()?->delete();
        $this->activityLogService->log($user, 'auth.logout', 'User logged out', $request);

        return $this->success(null, 'Logout successful');
    }

    public function forgotPassword(Request $request)
    {
        $request->validate(['email' => ['required', 'email']]);
        $email = Str::lower($request->string('email')->toString());
        $key = 'password-reset:' . $email . ':' . $request->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            return $this->error('Too many reset attempts. Please try again later.', 429);
        }

        RateLimiter::hit($key, 3600);
        $user = User::where('email', $email)->first();

        if ($user) {
            $code = (string) random_int(100000, 999999);
            DB::table('password_reset_tokens')->updateOrInsert(
                ['email' => $email],
                ['token' => Hash::make($code), 'created_at' => now()]
            );

            try {
                Mail::raw("Riva Password Reset Code: {$code}\nThis code expires in 15 minutes.", function ($message) use ($email) {
                    $message->to($email)->subject('Riva Password Reset Code');
                });
            } catch (\Throwable $e) {
                Log::warning('Password reset mail failed', [
                    'email' => $email,
                    'error' => $e->getMessage(),
                ]);

                return $this->error('We could not send the reset code right now. Please check mail settings or try again later.', 503);
            }

            $this->notificationService->notify($user, 'password_reset', 'Password reset requested', 'A reset code was sent to your email.');
        }

        return $this->success(null, 'If the email exists, a reset code has been sent.');
    }

    public function verifyResetCode(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'code' => ['required', 'digits:6'],
        ]);

        $record = DB::table('password_reset_tokens')->where('email', Str::lower($data['email']))->first();

        if (!$record || Carbon::parse($record->created_at)->addMinutes(15)->isPast() || !Hash::check($data['code'], $record->token)) {
            return $this->error('Invalid or expired verification code.', 422);
        }

        return $this->success(['reset_token' => $data['code']], 'Code verified successfully.');
    }

    public function resetPassword(Request $request)
    {
        $data = $request->validate([
            'code' => ['required_without:token', 'digits:6'],
            'token' => ['nullable', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', 'min:8'],
        ]);

        $email = Str::lower($data['email']);
        $code = $data['code'] ?? $data['token'];
        $record = DB::table('password_reset_tokens')->where('email', $email)->first();

        if (!$record || Carbon::parse($record->created_at)->addMinutes(15)->isPast() || !Hash::check($code, $record->token)) {
            return $this->error('Invalid or expired verification code.', 422);
        }

        $user = User::where('email', $email)->firstOrFail();
        $user->forceFill(['password' => $data['password']])->save();
        DB::table('password_reset_tokens')->where('email', $email)->delete();
        $this->notificationService->notify($user, 'password_changed', 'Password changed', 'Your password was changed successfully.');

        return $this->success(null, 'Password reset successfully.');
    }

    public function googleRedirect()
    {
        if (!config('services.google.client_id') || !config('services.google.client_secret')) {
            return $this->error('Google OAuth is not configured. Please set GOOGLE_CLIENT_ID and GOOGLE_CLIENT_SECRET.', 503);
        }

        $targetUrl = Socialite::driver('google')->stateless()->redirect()->getTargetUrl();

        if (request()->expectsJson()) {
            return $this->success(['url' => $targetUrl]);
        }

        return redirect()->away($targetUrl);
    }

    public function googleCallback()
    {
        $googleUser = Socialite::driver('google')->stateless()->user();
        $nameParts = explode(' ', trim($googleUser->getName() ?: ''), 2);

        $user = User::firstOrCreate(
            ['email' => $googleUser->getEmail()],
            [
                'first_name' => $nameParts[0] ?: 'RIVA',
                'last_name' => $nameParts[1] ?? 'User',
                'name' => $googleUser->getName() ?: $googleUser->getEmail(),
                'role' => 'patient',
                'password' => Str::random(32),
                'email_verified_at' => now(),
            ]
        );

        $user->forceFill([
            'google_id' => $googleUser->getId(),
            'google_avatar' => $googleUser->getAvatar(),
            'email_verified_at' => $user->email_verified_at ?: now(),
        ])->save();

        $token = $user->createToken('google-auth-token')->plainTextToken;
        $payload = http_build_query([
            'token' => $token,
            'role' => $user->role,
            'complete' => $user->profile_completed_at ? '0' : '1',
        ]);

        return redirect()->away(rtrim((string) env('FRONTEND_URL', 'http://127.0.0.1:4200'), '/') . '/auth/google/callback?' . $payload);
    }
    public function setRole(Request $request)
{
    $data = $request->validate([
        'role' => ['required', 'in:patient,doctor,caregiver'],
    ]);
    $user = $request->user();
    
    $user->forceFill(['role' => $data['role']])->save();
    $this->createRoleProfile($user);
    
    $user->tokens()->delete();
    $newToken = $user->createToken('auth-token')->plainTextToken;
    
    return response()->json([
        'message' => 'Role set successfully',
        'user' => $this->formatUser($user->fresh(['patient', 'doctor', 'caregiver'])),
        'token' => $newToken,
    ]);
}

    public function completeGoogleProfile(Request $request)
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'role' => ['required', 'in:patient,doctor,caregiver'],
        ]);

        $token = \Laravel\Sanctum\PersonalAccessToken::findToken($data['token']);
        abort_unless($token?->tokenable instanceof User, 401);

        $user = $token->tokenable;
        $user->forceFill(['role' => $data['role'], 'profile_completed_at' => now()])->save();
        $this->createRoleProfile($user);

        return $this->success([
            'user' => $this->formatUser($user->fresh(['patient', 'doctor', 'caregiver'])),
        ], 'Google profile completed.');
    }

    private function createRoleProfile(User $user): void
    {
        match ($user->role) {
            'patient' => Patient::firstOrCreate(['user_id' => $user->id]),
            'doctor' => Doctor::firstOrCreate(['user_id' => $user->id]),
            'caregiver' => Caregiver::firstOrCreate(['user_id' => $user->id]),
            default => null,
        };
    }

    private function formatUser(User $user): array
    {
        return [
            'id' => $user->id,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'address' => $user->address,
            'profile_image' => $user->profile_image,
            'profile_image_url' => $user->profile_image_url,
            'role' => $user->role,
            'profile_completed' => (bool) $user->profile_completed_at,
            'profile_completed_at' => $user->profile_completed_at,
            'patient' => $user->patient,
            'doctor' => $user->doctor,
            'caregiver' => $user->caregiver,
        ];
    }
}
