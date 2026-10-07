<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * La conciliación de una fecha (documento de Fran del 2026-10-06, §12 y §14): los pagos digitales registrados ese día
 * y los extractos que hay que subir para verificarlos. Cada fecha es independiente de las demás.
 */
class ReconciliationDay extends Model
{
    public const PENDING = 'pending';     // falta subir algún extracto
    public const REVIEW = 'review';       // hay pagos no encontrados o por revisar
    public const COMPLETED = 'completed'; // todos conciliados o resueltos a mano (§25)

    protected $fillable = ['date', 'status', 'completed_at'];

    protected $casts = [
        'date' => 'date:Y-m-d',
        'completed_at' => 'datetime',
    ];

    public function items()
    {
        return $this->hasMany(ReconciliationItem::class);
    }

    public function statements()
    {
        return $this->hasMany(BankStatement::class);
    }
}
