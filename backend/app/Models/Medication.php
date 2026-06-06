<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Medication extends Model
{
    protected $fillable = [
        'patient_id', 'name', 'dosage', 'schedule_time', 'frequency', 'start_date', 'end_date', 'instructions', 'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'start_date' => 'date',
        'end_date' => 'date',
    ];

    public function patient() { return $this->belongsTo(Patient::class); }
    public function logs() { return $this->hasMany(MedicationLog::class); }
}
