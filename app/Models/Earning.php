<?php

namespace App\Models;

use App\Services\SalesGroups\LeaderCommissions;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Earning extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'user_id',
        'role_type',
        'amount_usd',
        'currency',
        'rate',
        'earning_date',
    ];

    /**
     * Al generarse la comisión de una vendedora, nace la parte de su Líder (LeaderCommissions).
     * Si eso falla, se registra el error pero no se pierde la comisión de la vendedora.
     */
    protected static function booted(): void
    {
        static::created(function (Earning $earning) {
            try {
                app(LeaderCommissions::class)->forEarning($earning);
            } catch (\Throwable $e) {
                report($e);
            }
        });
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
