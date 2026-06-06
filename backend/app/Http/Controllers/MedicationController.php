<?php

namespace App\Http\Controllers;

use App\Models\Medication;
use App\Models\MedicationLog;
use App\Models\Patient;
use App\Models\PatientCaregiverRelationship;
use App\Models\PatientDoctorRelationship;
use App\Services\MedicationAdherenceService;
use App\Services\NotificationService;
use Illuminate\Support\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class MedicationController extends Controller
{
    public function __construct(
        private readonly NotificationService $notificationService,
        private readonly MedicationAdherenceService $adherenceService
    )
    {
    }

    public function index(Request $request)
    {
        $patient = $this->resolvePatient($request);
        abort_unless($patient, 403);

        $medications = Medication::where('patient_id', $patient->id)->where('is_active', true)->latest()->get();
        return $this->success($medications->map(fn (Medication $medication) => $this->serializeMedication($medication))->values());
    }

    public function todaySchedule(Request $request)
    {
        $patient = $this->resolvePatient($request);
        abort_unless($patient, 403);

        return $this->success($this->adherenceService->todaySchedule($patient));
    }

    public function patientToday(Request $request)
    {
        $patient = $request->user()->patient;
        abort_unless($patient, 403);

        return $this->success($this->adherenceService->todaySchedule($patient));
    }

    public function store(Request $request)
    {
        $patient = $request->user()->patient;
        abort_unless($patient, 403);

        $request->merge([
            'name' => $request->input('name')
                ?? $request->input('drug_name')
                ?? $request->input('med_name')
                ?? $request->input('medicine_name'),
            'schedule_time' => $this->normalizeRequestTime($request->input('schedule_time') ?? $request->input('reminder_time')),
        ]);

        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'drug_name' => ['nullable', 'string', 'max:255'],
            'med_name' => ['nullable', 'string', 'max:255'],
            'medicine_name' => ['nullable', 'string', 'max:255'],
            'dosage' => ['required', 'string', 'max:255'],
            'schedule_time' => ['nullable', 'date_format:H:i'],
            'reminder_time' => ['nullable', 'string'],
            'frequency' => ['nullable', 'string', 'max:255'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'instructions' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
        ]);

        if (blank($data['name'] ?? null)) {
            throw ValidationException::withMessages([
                'drug_name' => ['Medication name is required.'],
            ]);
        }

        $medication = Medication::create([
            'patient_id' => $patient->id,
            'name' => $data['name'],
            'dosage' => $data['dosage'],
            'schedule_time' => $data['schedule_time'] ?? null,
            'frequency' => $data['frequency'] ?? 'daily',
            'start_date' => $data['start_date'] ?? today()->toDateString(),
            'end_date' => $data['end_date'] ?? null,
            'instructions' => $data['instructions'] ?? $data['notes'] ?? null,
            'is_active' => true,
        ]);

        return $this->success($this->serializeMedication($medication), 'Medication created successfully', 201);
    }

    public function show(Request $request, Medication $medication)
    {
        $this->authorizeMedicationAccess($request, $medication);
        return $this->success($this->serializeMedication($medication));
    }

    public function update(Request $request, Medication $medication)
    {
        $this->authorizeMedicationOwner($request, $medication);

        $request->merge([
            'name' => $request->input('name')
                ?? $request->input('drug_name')
                ?? $request->input('med_name')
                ?? $request->input('medicine_name'),
            'schedule_time' => $this->normalizeRequestTime($request->input('schedule_time') ?? $request->input('reminder_time')),
        ]);

        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'drug_name' => ['nullable', 'string', 'max:255'],
            'med_name' => ['nullable', 'string', 'max:255'],
            'medicine_name' => ['nullable', 'string', 'max:255'],
            'dosage' => ['nullable', 'string', 'max:255'],
            'schedule_time' => ['nullable', 'date_format:H:i'],
            'reminder_time' => ['nullable', 'string'],
            'frequency' => ['nullable', 'string', 'max:255'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'instructions' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $medication->update([
            'name' => $data['name'] ?? $data['drug_name'] ?? $medication->name,
            'dosage' => $data['dosage'] ?? $medication->dosage,
            'schedule_time' => $data['schedule_time'] ?? $medication->schedule_time,
            'frequency' => $data['frequency'] ?? $medication->frequency,
            'start_date' => $data['start_date'] ?? $medication->start_date,
            'end_date' => $data['end_date'] ?? $medication->end_date,
            'instructions' => $data['instructions'] ?? $data['notes'] ?? $medication->instructions,
            'is_active' => $data['is_active'] ?? $medication->is_active,
        ]);

        return $this->success($this->serializeMedication($medication->fresh()), 'Medication updated successfully');
    }

    public function destroy(Request $request, Medication $medication)
    {
        $this->authorizeMedicationOwner($request, $medication);
        $medication->update(['is_active' => false]);

        return $this->success(null, 'Medication deleted successfully');
    }

    public function take(Request $request, Medication $medication) { return $this->mark($request, $medication, 'taken'); }
    public function snooze(Request $request, Medication $medication) { return $this->mark($request, $medication, 'snoozed'); }
    public function missed(Request $request, Medication $medication) { return $this->mark($request, $medication, 'missed'); }
    public function needHelp(Request $request, Medication $medication) { return $this->mark($request, $medication, 'need_help'); }

    public function takeDose(Request $request, MedicationLog $dose)
    {
        return $this->markDose($request, $dose, 'taken');
    }

    public function snoozeDose(Request $request, MedicationLog $dose)
    {
        $data = $request->validate([
            'minutes' => ['nullable', 'integer', 'min:5', 'max:240'],
        ]);

        return $this->markDose($request, $dose, 'snoozed', now()->addMinutes($data['minutes'] ?? 30));
    }

    public function adherenceSummary(Request $request)
    {
        $patient = $this->resolvePatient($request);
        abort_unless($patient, 403);

        $this->adherenceService->todaySchedule($patient);
        $logs = MedicationLog::where('patient_id', $patient->id)->get();
        $total = max($logs->count(), 1);
        $taken = $logs->where('status', 'taken')->count();

        return $this->success([
            'total_logs' => $logs->count(),
            'taken_logs' => $taken,
            'adherence_percentage' => round(($taken / $total) * 100, 2),
        ]);
    }

    public function detectMissed()
    {
        $logs = $this->adherenceService->duePendingLogs(null, 30);

        foreach ($logs as $log) {
            $this->adherenceService->confirmDose($log, 'missed', null);
            $this->notifyMissedOnce($log->fresh(['patient', 'medication']));
        }

        return $this->success([
            'updated_logs' => $logs->count(),
        ], 'Missed medications detected');
    }

    private function mark(Request $request, Medication $medication, string $status)
    {
        $this->authorizeMedicationOwner($request, $medication);

        $this->adherenceService->todaySchedule($medication->patient);
        $log = MedicationLog::query()
            ->where('medication_id', $medication->id)
            ->where('patient_id', $medication->patient_id)
            ->whereDate('scheduled_for_date', today())
            ->whereNull('status')
            ->orderBy('scheduled_at')
            ->firstOrFail();

        return $this->markDose($request, $log, $status);
    }

    private function markDose(Request $request, MedicationLog $log, string $status, ?Carbon $snoozedUntil = null)
    {
        $log->loadMissing(['medication', 'patient']);
        abort_unless($request->user()->patient?->id === $log->patient_id, 403);

        if ($log->status && $status === 'taken') {
            return $this->success([
                'dose' => $this->adherenceService->transformDose($log),
                'schedule' => $this->adherenceService->todaySchedule($log->patient),
            ], 'Dose was already handled.');
        }

        $log = $this->adherenceService->confirmDose($log, $status, $request->user()->id, $snoozedUntil);

        if (in_array($status, ['missed', 'need_help'], true)) {
            $severity = $status === 'need_help' ? 'high' : 'warning';
            $message = $status === 'need_help'
                ? 'A patient requested help with medication.'
                : 'A patient missed a medication dose.';

            $this->notifySupportNetwork($log->patient, 'medication_'.$status, ucfirst(str_replace('_', ' ', $status)), $message, $severity, MedicationLog::class, $log->id);
        }

        return $this->success([
            'dose' => $this->adherenceService->transformDose($log),
            'schedule' => $this->adherenceService->todaySchedule($log->patient),
        ], $status === 'snoozed' ? 'Medication reminder snoozed.' : 'Medication status updated successfully');
    }

    private function notifySupportNetwork(Patient $patient, string $type, string $title, string $message, string $severity, string $relatedType, int $relatedId): void
    {
        PatientDoctorRelationship::with('doctor.user')
            ->where('patient_id', $patient->id)
            ->whereIn('status', ['active', 'accepted'])
            ->get()
            ->each(fn ($relationship) => $relationship->doctor?->user
                ? $this->notificationService->create($relationship->doctor->user, $type, $title, $message, $severity, $relatedType, $relatedId)
                : null);

        PatientCaregiverRelationship::with('caregiver.user')
            ->where('patient_id', $patient->id)
            ->whereIn('status', ['active', 'accepted'])
            ->get()
            ->each(fn ($relationship) => $relationship->caregiver?->user
                ? $this->notificationService->create($relationship->caregiver->user, $type, $title, $message, $severity, $relatedType, $relatedId)
                : null);
    }

    public function notifyMissedOnce(MedicationLog $log): void
    {
        if ($log->missed_notification_sent_at) {
            return;
        }

        $name = $log->medication?->name ?: 'A scheduled medication';
        $this->notifySupportNetwork(
            $log->patient,
            'medication_missed',
            'Medication missed',
            "{$name} was missed at {$log->scheduled_time}.",
            'warning',
            MedicationLog::class,
            $log->id
        );

        $log->forceFill(['missed_notification_sent_at' => now()])->save();
    }

    private function resolvePatient(Request $request): ?Patient
    {
        $user = $request->user();
        return $user->patient;
    }

    private function authorizeMedicationAccess(Request $request, Medication $medication): void
    {
        if ($request->user()->role === 'patient') {
            abort_unless($request->user()->patient?->id === $medication->patient_id, 403);
            return;
        }
    }

    private function authorizeMedicationOwner(Request $request, Medication $medication): void
    {
        abort_unless($request->user()->patient?->id === $medication->patient_id, 403);
    }

    private function normalizeRequestTime(?string $time): ?string
    {
        if (blank($time)) {
            return null;
        }

        $time = trim($time);
        return strlen($time) === 8 ? substr($time, 0, 5) : $time;
    }

    private function serializeMedication(Medication $medication): array
    {
        $time = $medication->schedule_time ? substr((string) $medication->schedule_time, 0, 5) : null;

        return [
            'id' => $medication->id,
            'patient_id' => $medication->patient_id,
            'name' => $medication->name,
            'drug_name' => $medication->name,
            'medicine_name' => $medication->name,
            'med_name' => $medication->name,
            'dosage' => $medication->dosage,
            'schedule_time' => $time,
            'reminder_time' => $time,
            'frequency' => $medication->frequency,
            'instructions' => $medication->instructions,
            'notes' => $medication->instructions,
            'start_date' => optional($medication->start_date)->toDateString(),
            'end_date' => optional($medication->end_date)->toDateString(),
            'is_active' => $medication->is_active,
            'created_at' => optional($medication->created_at)->toISOString(),
            'updated_at' => optional($medication->updated_at)->toISOString(),
        ];
    }
}
