<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Target CPA y Break-even CPA de un producto (Módulo 2, §34), cargados a mano por el Admin. Cada cambio es una fila
 * nueva con su fecha de vigencia (§49), así se sabe cuál regía cada día.
 */
class ProductAdTarget extends Model
{
    protected $fillable = ['product_id', 'target_cpa', 'break_even_cpa', 'valid_from', 'created_by'];

    protected $casts = [
        'target_cpa' => 'decimal:2',
        'break_even_cpa' => 'decimal:2',
        'valid_from' => 'date:Y-m-d',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
