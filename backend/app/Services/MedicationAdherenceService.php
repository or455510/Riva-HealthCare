<?php

namespace App\Services;

use App\Models\Medication;
use App\Models\MedicationLog;
use App\Models\Patient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class MedicationAdherenceService
{
    private const HANDLED_STATUSES = ['taken', 'missed', 'snoozed', 'need_help', 'skipped'];
    private const PENDING_STATUSES = [null, 'pending'];

    public function todaySchedule(Patient $patient): array
    {
        return $this->scheduleForDate($patient, today(config('app.timezone')));
    }

    public function scheduleForDate(Patient $patient, Carbon|string $date): array
    {
        $date = Carbon::parse($date)->startOfDay();
        $logs = $this->ensureDoseLogsForDate($patient, $date);

        return $this->buildSchedulePayload($patient, $date, $logs);
    }

    public function confirmDose(MedicationLog $dose, string $status, ?int $confirmedBy, ?Carbon $snoozedUntil = null): MedicationLog
    {
        $payload = [
            'status' => $status,
            'confirmed_by' => $confirmedBy,
            'taken_at' => $status === 'taken' ? now(config('app.timezone')) : null,
            'missed_at' => $status === 'missed' ? now(config('app.timezone')) : null,
            'snoozed_until' => $status === 'snoozed' ? $snoozedUntil : null,
        ];

        $dose->update($payload);

        return $dose->fresh(['medication']);
    }

    public function duePendingLogs(?Patient $patient = null, int $graceMinutes = 30): Collection
    {
        if ($patient) {
            $this->ensureDoseLogsForDate($patient, today());
        }

        $cutoff = now(config('app.timezone'))->subMinutes($graceMinutes);

        return MedicationLog::with(['patient.user', 'medication'])
            ->when($patient, fn ($query) => $query->where('patient_id', $patient->id))
            ->where(fn ($query) => $query->whereNull('status')->orWhere('status', 'pending'))
            ->where('scheduled_at', '<=', $cutoff)
            ->get();
    }

    public function transformDose(MedicationLog $log): array
    {
        $log->loadMissing('medication');

        return [
            'id' => $log->id,
            'patient_id' => $log->patient_id,
            'medication_id' => $log->medication_id,
            'name' => $log->medication?->name,
            'dosage' => $log->medication?->dosage,
            'instructions' => $log->medication?->instructions,
            'scheduled_date' => $log->scheduled_for_date?->toDateString(),
            'scheduled_for_date' => $log->scheduled_for_date?->toDateString(),
            'scheduled_time' => $this->normalizeTime($log->scheduled_time),
            'schedule_time' => $this->normalizeTime($log->scheduled_time),
            'scheduled_at' => $log->scheduled_at,
            'status' => $log->status ?? 'pending',
            'taken_at' => $log->taken_at,
            'missed_at' => $log->missed_at,
            'snoozed_until' => $log->snoozed_until,
        ];
    }

    private function buildSchedulePayload(Patient $patient, Carbon $date, Collection $logs): array
    {
        $pending = $logs->filter(fn (MedicationLog $log) => in_array($log->status, self::PENDING_STATUSES, true))->values();
        $completed = $logs->where('status', 'taken')->values();
        $missed = $logs->where('status', 'missed')->values();
        $handled = $logs->filter(fn (MedicationLog $log) => in_array($log->status, self::HANDLED_STATUSES, true))->count();
        $todayTotal = $logs->count();
        $todayTaken = $completed->count();

        $weekStatuses = $this->weekdayStatuses($patient, $date);
        $dueWeek = collect($weekStatuses)->sum('due_doses');
        $takenWeek = collect($weekStatuses)->sum('taken_doses');

        return [
            'date' => $date->toDateString(),
            'pending_doses' => $pending->map(fn (MedicationLog $log) => $this->transformDose($log))->values(),
            'completed_doses' => $completed->map(fn (MedicationLog $log) => $this->transformDose($log))->values(),
            'missed_doses' => $missed->map(fn (MedicationLog $log) => $this->transformDose($log))->values(),
            'doses' => $logs->map(fn (MedicationLog $log) => $this->transformDose($log))->values(),
            'current_due' => $pending->first() ? $this->transformDose($pending->first()) : null,
            'all_completed_today' => $todayTotal > 0 && $pending->isEmpty() && $missed->isEmpty() && $handled === $todayTotal,
            'has_medications_today' => $todayTotal > 0,
            'treatment_completed' => $todayTotal === 0 && $this->hasEndedTreatment($patient, $date),
            'adherence_today' => $todayTotal > 0 ? round(($todayTaken / $todayTotal) * 100, 2) : 0,
            'adherence_week_so_far' => $dueWeek > 0 ? round(($takenWeek / $dueWeek) * 100, 2) : 0,
            'weekday_statuses' => $weekStatuses,
            'summary' => [
                'total' => $todayTotal,
                'handled' => $handled,
                'taken' => $todayTaken,
                'pending' => $pending->count(),
                'missed' => $missed->count(),
                'complete' => $todayTotal > 0 && $pending->isEmpty() && $handled === $todayTotal,
                'adherence_percentage' => $dueWeek > 0 ? round(($takenWeek / $dueWeek) * 100, 2) : 0,
            ],
        ];
    }

    private function ensureDoseLogsForDate(Patient $patient, Carbon $date): Collection
    {
        $medications = Medication::query()
            ->where('patient_id', $patient->id)
            ->where('is_active', true)
            ->where(fn ($query) => $query->whereNull('start_date')->orWhereDate('start_date', '<=', $date->toDateString()))
            ->where(fn ($query) => $query->whereNull('end_date')->orWhereDate('end_date', '>=', $date->toDateString()))
            ->orderBy('schedule_time')
            ->get();

        foreach ($medications as $medication) {
            if (!$this->isMedicationScheduledOn($medication, $date)) {
                continue;
            }

            foreach ($this->scheduledTimes($medication) as $time) {
                // UI-entered medication times are local wall-clock times; parse them in the app timezone.
                $scheduledAt = Carbon::parse($date->toDateString() . ' ' . $time, config('app.timezone'));
                $existingLog = MedicationLog::query()
                    ->where('patient_id', $medication->patient_id)
                    ->where('medication_id', $medication->id)
                    ->whereDate('scheduled_for_date', $date->toDateString())
                    ->where('scheduled_time', $time)
                    ->first();

                if (!$existingLog) {
                    MedicationLog::create([
                        'patient_id' => $medication->patient_id,
                        'medication_id' => $medication->id,
                        'scheduled_for_date' => $date->toDateString(),
                        'scheduled_time' => $time,
                        'scheduled_at' => $scheduledAt,
                    ]);
                }
            }
        }

        return MedicationLog::with('medication')
            ->where('patient_id', $patient->id)
            ->whereDate('scheduled_for_date', $date->toDateString())
            ->whereHas('medication', fn ($query) => $query->where('is_active', true))
            ->orderBy('scheduled_at')
            ->get();
    }

    private function weekdayStatuses(Patient $patient, Carbon $date): array
    {
        $start = $date->copy()->startOfWeek(Carbon::SUNDAY);
        $today = today(config('app.timezone'));
        $days = [];

        for ($i = 0; $i < 7; $i++) {
            $day = $start->copy()->addDays($i);
            $isFuture = $day->gt($today);
            $logs = $isFuture ? collect() : $this->ensureDoseLogsForDate($patient, $day);
            $dueLogs = $logs->filter(fn (MedicationLog $log) => $day->lt($today) || $log->scheduled_at?->lte(now(config('app.timezone'))));
            $total = $logs->count();
            $taken = $logs->where('status', 'taken')->count();
            $missed = $logs->where('status', 'missed')->count();
            $pending = $logs->filter(fn (MedicationLog $log) => in_array($log->status, self::PENDING_STATUSES, true))->count();

            $status = 'none';
            if ($isFuture) {
                $status = 'future';
            } elseif ($total > 0 && $taken === $total) {
                $status = 'completed';
            } elseif ($missed > 0) {
                $status = $taken > 0 ? 'partial' : 'missed';
            } elseif ($taken > 0 || ($pending > 0 && $day->isSameDay($today))) {
                $status = 'partial';
            } elseif ($total > 0 && $day->lt($today)) {
                $status = 'missed';
            }

            $days[] = [
                'date' => $day->toDateString(),
                'day' => $day->format('D'),
                'label' => $day->format('D'),
                'status' => $status,
                'total_doses' => $total,
                'due_doses' => $dueLogs->count(),
                'taken_doses' => $dueLogs->where('status', 'taken')->count(),
                'missed_doses' => $missed,
                'is_future' => $isFuture,
                'is_today' => $day->isSameDay($today),
            ];
        }

        return $days;
    }

    private function isMedicationScheduledOn(Medication $medication, Carbon $date): bool
    {
        $frequency = strtolower((string) $medication->frequency);
        if ($frequency === 'weekly') {
            $start = $medication->start_date ?: $medication->created_at;
            return Carbon::parse($start)->dayOfWeek === $date->dayOfWeek;
        }

        return true;
    }

    private function scheduledTimes(Medication $medication): array
    {
        $time = $this->normalizeTime($medication->schedule_time ?: '09:00:00');
        return [$time];
    }

    private function hasEndedTreatment(Patient $patient, Carbon $date): bool
    {
        return Medication::where('patient_id', $patient->id)
            ->where('is_active', true)
            ->whereNotNull('end_date')
            ->whereDate('end_date', '<', $date->toDateString())
            ->exists();
    }

    private function normalizeTime(?string $time): string
    {
        if (!$time) {
            return '09:00:00';
        }

        return strlen($time) === 5 ? $time . ':00' : $time;
    }
}
