<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReportComment extends Model
{
    protected $fillable = [
        'report_id', 'user_id', 'commenter_role', 'comment', 'is_read_by_patient',
    ];

    protected $casts = ['is_read_by_patient' => 'boolean'];

    public function report() { return $this->belongsTo(Report::class); }
    public function user() { return $this->belongsTo(User::class); }
}
