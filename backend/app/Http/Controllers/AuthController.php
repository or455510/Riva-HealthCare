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
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Socialite\Facades\Socialite;

class AuthController extends Controller
{
    public function __construct(
        private readonly ActivityLogService $activityLogService,
        private readonly NotificationService $notificationService
    ) {}

    public function register(Request $request)
    {
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:255'],
            'password' => ['required', 'confirmed', 'min:8'],
            // SECURITY (Phase 0): 'admin' intentionally removed from the accepted values.
            // This endpoint is public/unauthenticated — allowing 'admin' here let anyone
            // self-register a platform administrator account. Admin accounts must now be
            // created via the `php artisan riva:create-admin` console command (see
            // app/Console/Commands/CreateAdminUser.php), which is only reachable by
            // whoever has shell/deploy access to the server.
            'role' => ['required', 'in:patient,doctor,caregiver'],
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

    /**
     * SECURITY (Phase 0 / S4): login previously had no throttling beyond the
     * global API rate limiter (which is shared across every endpoint and every
     * user, so it does nothing to stop a credential-stuffing attempt against one
     * specific account). Every LoginAttempt row was already being recorded, but
     * nothing ever read them to block repeated failures. Mirrors the pattern
     * already used for forgotPassword() below: key on email+IP so one attacker
     * can't lock out a legitimate user by IP alone, only failed attempts count
     * against the limit, and a successful login clears it.
     */
    public function login(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $email = Str::lower($data['email']);
        $throttleKey = 'login:'.$email.':'.$request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);

            return $this->error(
                "Too many login attempts. Please try again in {$seconds} seconds.",
                429
            );
        }

        $user = User::where('email', $data['email'])->first();
        $successful = $user && Hash::check($data['password'], $user->password);

        // RIVA notification-noise audit (product-owner feedback, 2026-08-25):
        // "the site sends a notification for EVERY login" — checked here,
        // BEFORE this login's own attempt is recorded below, against the
        // real LoginAttempt history for this account: has a successful
        // login ever happened from this exact IP before? This is the same
        // "new device/location" pattern real sites (Google, banks, etc.)
        // use to alert only on logins worth a second look, not routine
        // day-to-day sign-ins from the same place.
        $isKnownIp = $successful && LoginAttempt::where('email', $data['email'])
            ->where('ip_address', $request->ip())
            ->where('successful', true)
            ->exists();

        LoginAttempt::create([
            'email' => $data['email'],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'successful' => (bool) $successful,
            'reason' => $successful ? null : 'Invalid credentials',
            'created_at' => now(),
        ]);

        if (! $successful || ! $user) {
            RateLimiter::hit($throttleKey, 60);

            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        RateLimiter::clear($throttleKey);

        if (! $user->is_active) {
            return $this->error('This account is inactive.', 403);
        }

        $user->forceFill(['last_login_at' => now()])->save();
        $token = $user->createToken('auth-token')->plainTextToken;
        $this->activityLogService->log($user, 'auth.login', 'User logged in', $request);

        // Only alert on a login from an IP this account hasn't successfully
        // logged in from before — every other real, everyday login stays
        // silent. When it does fire, the message carries the actual detail
        // (device/browser, IP, timestamp) instead of a one-line generic
        // "a login was detected", so the account owner can tell at a glance
        // whether it was them.
        if (! $isKnownIp) {
            $device = $this->describeUserAgent($request->userAgent());
            $when = now()->format('M j, Y \a\t g:i A');
            $this->notificationService->notify(
                $user,
                'login_alert',
                'New sign-in to your account',
                "A new sign-in was detected from {$device}, IP {$request->ip()}, on {$when}. If this wasn't you, change your password immediately.",
                ['ip_address' => $request->ip(), 'user_agent' => $request->userAgent()],
                null,
                true
            );
        }

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
        $key = 'password-reset:'.$email.':'.$request->ip();

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

            // SECURITY (RIVA-118, P0): this used to `return` a distinct 503 here on
            // mail failure — but that only ever ran inside `if ($user)`, so an
            // attacker could distinguish "this email exists but mail is broken"
            // (503) from "this email doesn't exist" (the generic success message
            // below) purely by response code, with no need to actually receive
            // mail — a user-enumeration oracle on a password-reset endpoint.
            // Fixed by always falling through to the same generic response
            // regardless of mail outcome; a failure is now an ops problem
            // surfaced only in logs, never a signal to the caller.
            try {
                Mail::raw("Riva Password Reset Code: {$code}\nThis code expires in 15 minutes.", function ($message) use ($email) {
                    $message->to($email)->subject('Riva Password Reset Code');
                });

                $this->notificationService->notify($user, 'password_reset', 'Password reset requested', 'A reset code was sent to your email.');
            } catch (\Throwable $e) {
                Log::warning('Password reset mail failed', [
                    'email' => $email,
                    'error' => $e->getMessage(),
                ]);
            }
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

        if (! $record || Carbon::parse($record->created_at)->addMinutes(15)->isPast() || ! Hash::check($data['code'], $record->token)) {
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

        if (! $record || Carbon::parse($record->created_at)->addMinutes(15)->isPast() || ! Hash::check($code, $record->token)) {
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
        if (! config('services.google.client_id') || ! config('services.google.client_secret')) {
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

        $existing = User::where('email', $googleUser->getEmail())->first();

        // SECURITY (RIVA-119, P0 — account takeover via OAuth email auto-link):
        // this used to be a plain User::firstOrCreate() keyed on email, which
        // silently logs the caller into ANY pre-existing account with a matching
        // email — including one created through normal password registration,
        // which this app never requires to be email-verified (register()/login()
        // have no verification gate at all). That made this attack possible:
        // (1) attacker registers victim@real-email.com with a password only the
        // attacker knows; (2) the real victim later clicks "Sign in with Google"
        // on their genuine victim@real-email.com Google account; (3) this method
        // found the attacker's existing row by email and happily issued the real
        // victim a token for THAT account — while the attacker's password still
        // works on it too. Both parties end up sharing one account with the
        // victim's real medical data, and the attacker never had to guess
        // anything. Fixed by refusing to silently adopt a pre-existing,
        // not-yet-Google-linked account: only a brand-new email or an email
        // already tied to this exact Google id is allowed through here.
        if ($existing && is_null($existing->google_id)) {
            $message = 'An account with this email already exists. Please log in with your password, or contact support to link Google sign-in to your existing account.';

            if (request()->expectsJson()) {
                return $this->error($message, 409);
            }

            $errorPayload = http_build_query(['error' => 'account_exists', 'message' => $message]);

            return redirect()->away(rtrim((string) env('FRONTEND_URL', 'http://127.0.0.1:4200'), '/').'/auth/google/callback?'.$errorPayload);
        }

        $user = $existing ?? User::create([
            'first_name' => $nameParts[0] ?: 'RIVA',
            'last_name' => $nameParts[1] ?? 'User',
            'name' => $googleUser->getName() ?: $googleUser->getEmail(),
            'email' => $googleUser->getEmail(),
            'role' => 'patient',
            'password' => Str::random(32),
            'email_verified_at' => now(),
        ]);

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

        return redirect()->away(rtrim((string) env('FRONTEND_URL', 'http://127.0.0.1:4200'), '/').'/auth/google/callback?'.$payload);
    }

    /**
     * SECURITY (RIVA-115 / P0 — role self-escalation): this endpoint exists
     * so a user who signed up via Google OAuth (which always defaults
     * `role` to 'patient' and leaves `profile_completed_at` null — see
     * googleCallback() above) can make their one-time, real role choice
     * afterward. As originally written it had NO check that this was still
     * that first-time choice — any already-onboarded, authenticated user of
     * ANY role, signed up through ANY path (not just OAuth), could call
     * this at any later time to flip their own role, e.g. patient -> doctor.
     * createRoleProfile() would then firstOrCreate() a real Doctor row for
     * them (is_active=true by default), which immediately appears in the
     * public GET /api/doctors listing and can write reports/diagnoses on
     * any patient with an accepted relationship — completely bypassing the
     * DoctorVerificationController/admin-approval pipeline. Guarded to only
     * ever apply to the pre-onboarding window (profile_completed_at still
     * null), matching what this endpoint was actually built for. Any role
     * change after onboarding is a deliberate admin action now, not
     * self-service.
     */
    public function setRole(Request $request)
    {
        $data = $request->validate([
            'role' => ['required', 'in:patient,doctor,caregiver'],
        ]);
        $user = $request->user();

        abort_unless(is_null($user->profile_completed_at), 403, 'Your role is already set and your profile is complete — it can no longer be changed this way.');

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

    /**
     * SECURITY (RIVA-115): same class of bug as setRole() above — this had
     * no check preventing an already-onboarded OAuth user from calling it
     * again later to change their role, as long as they still held (or
     * could obtain) a valid Sanctum token. Same guard, same reasoning.
     */
    public function completeGoogleProfile(Request $request)
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'role' => ['required', 'in:patient,doctor,caregiver'],
        ]);

        $token = PersonalAccessToken::findToken($data['token']);
        abort_unless($token?->tokenable instanceof User, 401);

        $user = $token->tokenable;
        abort_unless(is_null($user->profile_completed_at), 403, 'Your role is already set and your profile is complete — it can no longer be changed this way.');

        $user->forceFill(['role' => $data['role'], 'profile_completed_at' => now()])->save();
        $this->createRoleProfile($user);

        return $this->success([
            'user' => $this->formatUser($user->fresh(['patient', 'doctor', 'caregiver'])),
        ], 'Google profile completed.');
    }

    /**
     * RIVA notification-detail audit (2026-08-25): a small, honest browser
     * and OS sniff for the new-device login alert — real detail ("Chrome on
     * Windows") instead of a raw user-agent string most people can't read,
     * but never guessing when a token doesn't match: an unrecognized string
     * stays honestly "an unrecognized device", never a fabricated browser.
     */
    private function describeUserAgent(?string $userAgent): string
    {
        if (! $userAgent) {
            return 'an unrecognized device';
        }

        $browser = match (true) {
            str_contains($userAgent, 'Edg/') => 'Edge',
            str_contains($userAgent, 'OPR/') || str_contains($userAgent, 'Opera') => 'Opera',
            str_contains($userAgent, 'Chrome/') => 'Chrome',
            str_contains($userAgent, 'CriOS/') => 'Chrome',
            str_contains($userAgent, 'FxiOS/') => 'Firefox',
            str_contains($userAgent, 'Firefox/') => 'Firefox',
            str_contains($userAgent, 'Safari/') && str_contains($userAgent, 'Version/') => 'Safari',
            default => null,
        };

        $os = match (true) {
            str_contains($userAgent, 'Windows') => 'Windows',
            str_contains($userAgent, 'Android') => 'Android',
            str_contains($userAgent, 'iPhone') || str_contains($userAgent, 'iPad') => 'iOS',
            str_contains($userAgent, 'Mac OS X') => 'macOS',
            str_contains($userAgent, 'Linux') => 'Linux',
            default => null,
        };

        if ($browser && $os) {
            return "{$browser} on {$os}";
        }

        return $browser ?? $os ?? 'an unrecognized device';
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
