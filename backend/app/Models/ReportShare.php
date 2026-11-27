<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * RIVA-142: see the create_report_shares_table migration's docblock — a row
 * is patient-granted access for a doctor to a report written by a DIFFERENT
 * doctor.
 */
class ReportShare extends Model
{
    protected $fillable = ['report_id', 'doctor_id'];

    public function report()
    {
        return $this->belongsTo(Report::class);
    }

    public function doctor()
    {
        return $this->belongsTo(Doctor::class);
    }
}
