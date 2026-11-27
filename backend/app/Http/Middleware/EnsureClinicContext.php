<?php

namespace App\Http\Middleware;

use App\Services\Tenancy\ClinicContextService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * PHASE 1 (Identity + Tenancy): gates every clinic-scoped route behind an
 * actual, verified active clinic membership — resolved via ClinicContextService
 * (see that class for the resolution rules). The resolved membership is
 * attached to the request as 'clinic_membership' for controllers to use; it is
 * never taken from client-supplied user_id/clinic_id alone.
 */
class EnsureClinicContext
{
    public function __construct(private readonly ClinicContextService $clinicContext) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $membership = $this->clinicContext->resolve($request, $user);
        $request->attributes->set('clinic_membership', $membership);

        return $next($request);
    }
}
