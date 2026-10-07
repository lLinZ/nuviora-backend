<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Un extracto que se sube para conciliar (documento de Fran del 2026-10-06, §2 y §12): un banco o una plataforma
 * (Banesco, Binance, Zinli…). Varias cuentas de la empresa pueden verificarse en el mismo extracto, como el pago
 * móvil y la transferencia de un mismo banco.
 */
class StatementSource extends Model
{
    protected $fillable = ['name', 'currency', 'format', 'is_active'];

    protected $casts = [
        'format' => 'array',
        'is_active' => 'boolean',
    ];

    public function accounts()
    {
        return $this->hasMany(CompanyAccount::class);
    }
}
