<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Hospital extends Model
{
    protected $fillable = ['name', 'address', 'phone'];
    public function doctors() { return $this->belongsToMany(Doctor::class, 'doctor_hospitals'); }
}
