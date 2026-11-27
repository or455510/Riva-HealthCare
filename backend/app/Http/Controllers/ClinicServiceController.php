<?php

namespace App\Http\Controllers;

use App\Models\Clinic;
use App\Models\ClinicService;
use App\Models\ClinicUser;
use Illuminate\Http\Request;

/**
 * Phase C (Services & Prices, principal-engineering directive): the first
 * real consumer of ClinicUser::PERMISSION_MANAGE_SERVICES — Slice 3 defined
 * the permission with nothing to manage; this is that thing. See
 * create_clinic_services_table's migration docblock for how a ClinicService
 * relates to Doctor::fee and Appointment.amount (AppointmentController::store()
 * derives amount from a service's price only when a valid, active, same-clinic
 * service_id is supplied — otherwise it stays on the existing Doctor::fee
 * fallback, unchanged).
 *
 * index() is readable by any active member of the clinic (staff need to see
 * prices to quote a walk-in even without services.manage); create/update/
 * delete are gated behind services.manage (owner/admin always pass, per
 * ClinicUser::hasPermission()).
 */
class ClinicServiceController extends Controller
{
    public function index(Request $request)
    {
        $clinic = $this->currentClinic($request);

        return $this->success(
            $clinic->services()->orderBy('name')->get()->map(fn (ClinicService $service) => $this->serialize($service))
        );
    }

    public function store(Request $request)
    {
        $clinic = $this->currentClinic($request);
        $this->authorizeManage($request);

        $data = $this->validated($request);

        $service = ClinicService::create([
            ...$data,
            'clinic_id' => $clinic->id,
        ]);

        return $this->success($this->serialize($service), 'Service created successfully', 201);
    }

    public function update(Request $request, ClinicService $service)
    {
        $clinic = $this->currentClinic($request);
        $this->authorizeManage($request);
        $this->assertServiceBelongsToClinic($service, $clinic);

        $data = $this->validated($request, partial: true);

        $service->update($data);

        return $this->success($this->serialize($service->fresh()), 'Service updated successfully');
    }

    /**
     * Historical financial integrity (master directive §14/§28): a service
     * that has ever been booked cannot be hard-deleted — doing so would null
     * out service_id on every historical Appointment row that references it
     * (see the migration's nullOnDelete()), silently erasing which priced
     * offering a past, possibly already-paid appointment was actually for.
     * A service with appointment history must be deactivated instead, which
     * keeps it fully intact for history while hiding it from new bookings.
     */
    public function destroy(Request $request, ClinicService $service)
    {
        $clinic = $this->currentClinic($request);
        $this->authorizeManage($request);
        $this->assertServiceBelongsToClinic($service, $clinic);

        if ($service->appointments()->exists()) {
            return $this->error(
                'This service has appointment history and cannot be deleted. Deactivate it instead so past appointments keep their historical record.',
                409
            );
        }

        $service->delete();

        return $this->success(null, 'Service deleted successfully');
    }

    private function validated(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'name' => [$required, 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'price' => [$required, 'numeric', 'min:0'],
            'duration_minutes' => ['nullable', 'integer', 'min:1'],
            'is_active' => ['nullable', 'boolean'],
        ]);
    }

    private function currentClinic(Request $request): Clinic
    {
        $membership = $request->attributes->get('clinic_membership');
        abort_unless($membership, 403, 'No active clinic context.');

        return $membership->clinic;
    }

    private function authorizeManage(Request $request): void
    {
        $membership = $request->attributes->get('clinic_membership');
        abort_unless(
            $membership->hasPermission(ClinicUser::PERMISSION_MANAGE_SERVICES),
            403,
            'You do not have permission to manage this clinic\'s services.'
        );
    }

    private function assertServiceBelongsToClinic(ClinicService $service, Clinic $clinic): void
    {
        abort_unless($service->clinic_id === $clinic->id, 404);
    }

    private function serialize(ClinicService $service): array
    {
        return [
            'id' => $service->id,
            'clinic_id' => $service->clinic_id,
            'name' => $service->name,
            'description' => $service->description,
            'price' => $service->price,
            'duration_minutes' => $service->duration_minutes,
            'is_active' => $service->is_active,
            'created_at' => $service->created_at,
            'updated_at' => $service->updated_at,
        ];
    }
}
