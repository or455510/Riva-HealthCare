<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Medication extends Model
{
    protected $fillable = [
        'patient_id', 'name', 'medicine_id', 'dosage', 'schedule_time', 'frequency', 'start_date', 'end_date', 'instructions', 'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'start_date' => 'date',
        'end_date' => 'date',
    ];

    public function patient()
    {
        return $this->belongsTo(Patient::class);
    }

    public function logs()
    {
        return $this->hasMany(MedicationLog::class);
    }

    /**
     * RIVA-132 follow-up: best-effort real catalog match, nullable — see the
     * add_medicine_id_to_medications_table migration's docblock.
     */
    public function medicine()
    {
        return $this->belongsTo(Medicine::class);
    }
}
