<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Un pago de honorarios a un rider por un día de trabajo (§18.5), con las carreras que cubre. */
class RiderPayout extends Model
{
    public $timestamps = false;

    protected $fillable = ['rider_id', 'center_id', 'pay_date', 'amount_usd', 'method', 'paid_by', 'paid_at', 'note', 'details', 'created_at'];

    protected $casts = [
        'pay_date' => 'date',
        'amount_usd' => 'float',
        'paid_at' => 'datetime',
        'details' => 'array',
        'created_at' => 'datetime',
    ];

    public function payer()
    {
        return $this->belongsTo(User::class, 'paid_by');
    }
}
