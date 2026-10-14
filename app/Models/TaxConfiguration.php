<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Impuestos, tasas y retenciones (Módulo 4 de Fran, §78): solo la estructura, para más adelante. Está desactivada y no
 * afecta la V1 (§109: la contabilidad fiscal y los impuestos no se incluyen todavía). Nada la lee hoy.
 */
class TaxConfiguration extends Model
{
    public const KINDS = ['tax' => 'Impuesto', 'rate' => 'Tasa', 'withholding' => 'Retención'];

    protected $fillable = ['name', 'kind', 'percent', 'applies_to', 'notes', 'is_active'];

    protected $casts = [
        'percent' => 'float',
        'is_active' => 'boolean',
    ];
}
