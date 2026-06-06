<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CaregiverReview extends Model
{
    protected $fillable = ['caregiver_id', 'patient_id', 'rating', 'comment'];

    public function caregiver() { return $this->belongsTo(Caregiver::class); }
    public function patient()   { return $this->belongsTo(Patient::class); }
}