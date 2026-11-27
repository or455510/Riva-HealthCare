<?php

namespace App\Services;

use App\Models\RecordAccessLog;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * SECURITY (Phase 0 / S5): a single, deliberately simple entry point for
 * recording "who read this medical record, and when." Called explicitly from
 * the specific read endpoints that expose medical data (see ReportController,
 * MedicationController) rather than via a blanket route-level middleware — this
 * codebase's routes mix medical and non-medical data on the same controllers, so
 * an explicit call at each genuinely sensitive read site is more accurate than a
 * broad middleware that would either over- or under-log.
 *
 * Deliberately fire-and-forget: a failure to write an audit row must never break
 * the underlying read request for the user, so any exception here is logged and
 * swallowed rather than propagated.
 */
class RecordAccessAuditService
{
    public function logView(User $actor, string $resourceType, int $resourceId, ?int $patientId = null, ?string $ip = null): void
    {
        try {
            RecordAccessLog::create([
                'user_id' => $actor->id,
                'actor_role' => $actor->role,
                'resource_type' => $resourceType,
                'resource_id' => $resourceId,
                'patient_id' => $patientId,
                'action' => 'view',
                'ip_address' => $ip,
                'created_at' => now(),
            ]);
        } catch (Throwable $exception) {
            Log::warning('RecordAccessAuditService: failed to write audit log row.', [
                'resource_type' => $resourceType,
                'resource_id' => $resourceId,
                'exception' => $exception->getMessage(),
            ]);
        }
    }
}
