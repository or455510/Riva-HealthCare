<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DailyStatus extends Model
{
    use HasFactory;

    protected $fillable = [
        'patient_id',
        'date',
        'mood',
        'pain_level',
        'sleep_hours',
        'sleep_quality',
        'symptoms',
        'weight',
        'blood_pressure_systolic',
        'blood_pressure_diastolic',
        'temperature',
        'heart_rate',
        'risk_level',
        'medication_taken',
        'notes',
        'additional_data',
    ];

    protected $casts = [
        'date' => 'date',
        'sleep_hours' => 'integer',
        'weight' => 'decimal:2',
        'temperature' => 'decimal:1',
        'heart_rate' => 'integer',
        'additional_data' => 'json',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // ✅ Validation Rules
    public static function rules(): array
    {
        return [
            'mood' => ['nullable', 'string', 'in:happy,sad,anxious,neutral'],
            'sleep_hours' => ['nullable', 'integer', 'min:0', 'max:24'],
            'sleep_quality' => ['nullable', 'string', 'in:good,fair,poor'],
            'weight' => ['nullable', 'numeric', 'min:0', 'max:300'],
            'blood_pressure_systolic' => ['nullable', 'integer', 'min:60', 'max:200'],
            'blood_pressure_diastolic' => ['nullable', 'integer', 'min:40', 'max:130'],
            'temperature' => ['nullable', 'numeric', 'min:35', 'max:42'],
            'heart_rate' => ['nullable', 'integer', 'min:30', 'max:200'],
            'risk_level' => ['required', 'string', 'in:stable,moderate,high'],
            'medication_taken' => ['nullable', 'string', 'in:yes,no,partial'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    // ✅ العلاقات
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    // ✅ Helper Methods
    public function isHighRisk(): bool
    {
        return $this->risk_level === 'high';
    }

    public function isModerateRisk(): bool
    {
        return $this->risk_level === 'moderate';
    }

    public function isStable(): bool
    {
        return $this->risk_level === 'stable';
    }

    public function calculateRiskLevel(): string
    {
        $riskScore = 0;

        // Temperature check
        if ($this->temperature) {
            if ($this->temperature > 38 || $this->temperature < 36) {
                $riskScore += 2;
            }
        }

        // Blood pressure check
        if ($this->blood_pressure_systolic && $this->blood_pressure_diastolic) {
            if ($this->blood_pressure_systolic > 140 || $this->blood_pressure_diastolic > 90) {
                $riskScore += 2;
            }
        }

        // Heart rate check
        if ($this->heart_rate) {
            if ($this->heart_rate > 100 || $this->heart_rate < 60) {
                $riskScore += 1;
            }
        }

        // Sleep check
        if ($this->sleep_hours && $this->sleep_hours < 5) {
            $riskScore += 1;
        }

        // Medication check
        if ($this->medication_taken === 'no') {
            $riskScore += 2;
        } elseif ($this->medication_taken === 'partial') {
            $riskScore += 1;
        }

        // Determine risk level
        if ($riskScore >= 4) {
            return 'high';
        } elseif ($riskScore >= 2) {
            return 'moderate';
        }

        return 'stable';
    }
}
