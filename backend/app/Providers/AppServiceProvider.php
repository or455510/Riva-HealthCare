<?php

namespace App\Providers;

use App\Services\Payments\ManualPaymentGateway;
use App\Services\Payments\PaymentGatewayInterface;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Payment architecture (principal-engineering directive): the one place
        // that chooses which gateway is active. Swapping in a real gateway later
        // is a one-line change here, not a controller/service rewrite — see
        // PaymentGatewayInterface's docblock.
        $this->app->bind(PaymentGatewayInterface::class, ManualPaymentGateway::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->assertAppUrlIsWellFormed();
        $this->registerRateLimiters();
    }

    /**
     * COST / SECURITY (RIVA-101): the API had no rate limiting at all outside
     * AuthController's hand-rolled login/forgot-password limiters (see
     * docs/RIVA_COST_MODEL.md and
     * backend/docs/engineering/RIVA_COST_AND_SCALABILITY_AUDIT.md §22 P0-1) —
     * the highest-leverage gap identified in the cost/security audit, since it
     * capped nothing on the endpoint that spends real external money per call
     * (ai-assistant/message -> Gemini). Applied via the default `api` limiter
     * (see bootstrap/app.php's $middleware->throttleApi()) plus explicit
     * `throttle:<name>` middleware on the specific routes named below (see
     * routes/api.php). Limits are deliberately generous for general API
     * traffic — this is about capping abuse/runaway cost, not throttling
     * normal Riva usage.
     */
    private function registerRateLimiters(): void
    {
        // Default limiter for the whole `api` middleware group.
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(120)->by($request->user()?->id ?: $request->ip());
        });

        // The one endpoint that spends real external money per call.
        RateLimiter::for('ai-assistant', function (Request $request) {
            return Limit::perMinute(6)->by($request->user()?->id ?: $request->ip());
        });

        // Public, unauthenticated, security-sensitive account endpoints
        // (register / forgot-password / resend-reset-code / verify-reset-code /
        // reset-password). Login already has its own stricter hand-rolled
        // limiter in AuthController and is intentionally left untouched.
        RateLimiter::for('auth-sensitive', function (Request $request) {
            return Limit::perMinute(10)->by($request->ip());
        });

        // Public, unauthenticated write endpoint with no auth-failure signal
        // of its own (contact-message).
        RateLimiter::for('public-write', function (Request $request) {
            return Limit::perMinute(20)->by($request->ip());
        });
    }

    /**
     * SECURITY / RELIABILITY (Phase 0): a malformed APP_URL silently corrupts every
     * backend-generated absolute URL (password-reset links, emergency-alert dashboard
     * links, Sanctum's own request-origin checks, and every URL::to()/route() call).
     * This previously shipped as "http://https://riva-healthcare-tm.gamer.gd" (a doubled
     * scheme) undetected. Fail loudly instead of silently generating broken links.
     */
    private function assertAppUrlIsWellFormed(): void
    {
        $url = (string) config('app.url');

        $isValid = $url !== ''
            && filter_var($url, FILTER_VALIDATE_URL) !== false
            && substr_count($url, '://') === 1;

        if ($isValid) {
            return;
        }

        $message = "Invalid APP_URL configuration detected: \"{$url}\". "
            .'Every absolute URL the backend generates (password reset links, '
            .'emergency-alert links, OAuth redirects) will be broken until this is fixed.';

        Log::critical($message);

        // Fail fast in local/dev/testing so this can never again ship unnoticed;
        // in a live environment we log critically but do not hard-crash the app,
        // since a boot-time exception would take the whole API down for every
        // request rather than just the URL-generation features that are already broken.
        if (app()->environment(['local', 'testing'])) {
            throw new \RuntimeException($message);
        }
    }
}
