<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Parte de la Líder sobre una comisión de una vendedora de su grupo (ver la migración). */
class LeaderCommission extends Model
{
    protected $fillable = [
        'earning_id', 'order_id', 'seller_id', 'leader_id', 'sales_group_id',
        'base_usd', 'pct', 'amount_usd', 'earning_date',
    ];

    protected $casts = [
        'base_usd' => 'float',
        'pct' => 'float',
        'amount_usd' => 'float',
        'earning_date' => 'date:Y-m-d',
    ];

    public function earning()
    {
        return $this->belongsTo(Earning::class);
    }

    public function seller()
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function leader()
    {
        return $this->belongsTo(User::class, 'leader_id');
    }

    public function group()
    {
        return $this->belongsTo(SalesGroup::class, 'sales_group_id');
    }
}
