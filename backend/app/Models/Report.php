<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Report extends Model
{
    protected $fillable = ['patient_id', 'doctor_id', 'title', 'summary', 'final_report'];

    public function patient()
    {
        return $this->belongsTo(Patient::class);
    }

    public function doctor()
    {
        return $this->belongsTo(Doctor::class);
    }

    public function comments()
    {
        return $this->hasMany(ReportComment::class);
    }

    // RIVA-142: rows the owning patient created to grant a specific doctor
    // (other than the one who wrote this report) access to it.
    public function reportShares()
    {
        return $this->hasMany(ReportShare::class);
    }
}
