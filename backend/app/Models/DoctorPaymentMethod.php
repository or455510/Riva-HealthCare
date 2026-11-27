<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * See create_doctor_payment_methods_table's migration docblock — this is a
 * doctor's own choice of which real-world payment rail they accept and the
 * account detail patients should use, not a payment-processing record.
 */
class DoctorPaymentMethod extends Model
{
    use HasFactory;

    protected $fillable = [
        'doctor_id',
        'method',
        'account_label',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }
}
