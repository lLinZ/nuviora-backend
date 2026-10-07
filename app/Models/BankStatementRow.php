<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Un ingreso de un extracto, normalizado (documento de Fran del 2026-10-06, §17): fecha, referencia, monto y la fila
 * del archivo de la que salió. Los egresos no se guardan (§23).
 */
class BankStatementRow extends Model
{
    protected $fillable = ['bank_statement_id', 'line', 'date', 'reference', 'reference_norm', 'description', 'amount', 'raw'];

    protected $casts = [
        'date' => 'date:Y-m-d',
        'amount' => 'float',
        'raw' => 'array',
    ];

    public function statement()
    {
        return $this->belongsTo(BankStatement::class, 'bank_statement_id');
    }

    /** Referencia para comparar: solo dígitos y sin ceros a la izquierda (§19). */
    public static function normalizeReference(?string $reference): ?string
    {
        $digits = ltrim(preg_replace('/\D/', '', (string) $reference), '0');

        return $digits === '' ? null : $digits;
    }
}
