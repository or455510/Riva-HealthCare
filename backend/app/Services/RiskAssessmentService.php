<?php

namespace App\Services;

use App\Models\DailyStatus;
use App\Models\MedicationLog;
use App\Models\Patient;

class RiskAssessmentService
{
    public function classify(array $payload, Patient $patient): string
    {
        $pain = (int) ($payload['pain_level'] ?? 0);
        $sleep = strtolower((string) ($payload['sleep_quality'] ?? ''));
        $mood = strtolower((string) ($payload['mood'] ?? ''));
        $medicationTaken = in_array($payload['medication_taken'] ?? false, [true, 1, '1', 'yes', 'taken'], true);
        $symptomsText = strtolower((string) ($payload['symptoms'] ?? ''));

        $dangerousSymptoms = [
            'chest pain',
            'shortness of breath',
            'fainting',
            'severe bleeding',
            'confusion',
            'very high fever',
        ];

        foreach ($dangerousSymptoms as $symptom) {
            if (str_contains($symptomsText, $symptom) || $pain >= 9) {
                return 'high_risk';
            }
        }

        $recentMissedCount = MedicationLog::query()
            ->where('patient_id', $patient->id)
            ->where('status', 'missed')
            ->where('created_at', '>=', now()->subDays(3))
            ->count();

        $previousPainAverage = DailyStatus::query()
            ->where('patient_id', $patient->id)
            ->latest()
            ->take(3)
            ->get()
            ->avg('pain_level');

        if ($recentMissedCount >= 2 || ($previousPainAverage && $pain > $previousPainAverage + 2) || $pain >= 7) {
            return 'moderate_risk';
        }

        if ($sleep === 'poor' || $sleep === 'bad' || in_array($mood, ['sad', 'low', 'angry', 'stressed'], true)) {
            return 'attention_needed';
        }

        if ($pain <= 3 && $medicationTaken && $symptomsText === '') {
            return 'stable';
        }

        return 'attention_needed';
    }
}
