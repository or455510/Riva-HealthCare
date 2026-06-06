<?php

namespace App\Models;


use Illuminate\Database\Eloquent\Model;

class Report extends Model
{
    protected $fillable = ['patient_id', 'doctor_id', 'title', 'summary', 'final_report'];

    public function patient() { return $this->belongsTo(Patient::class); }
    public function doctor() { return $this->belongsTo(Doctor::class); }
    public function comments() { return $this->hasMany(ReportComment::class); }
}