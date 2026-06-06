<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Disease extends Model
{
    protected $fillable = ['title', 'slug', 'overview', 'symptoms', 'causes', 'treatment_info', 'tips', 'image'];
}
