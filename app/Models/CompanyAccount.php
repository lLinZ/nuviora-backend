<?php

namespace App\Models;

use App\Services\Payments\ReceiptChecker;
use App\Services\Payments\ReceiptReader;
use Illuminate\Database\Eloquent\Model;

class CompanyAccount extends Model
{
    protected $fillable = [
        'name',
        'icon',
        'method',
        'currency',
        'statement_source_id',
        'details',
        'is_active',
    ];

    protected $casts = [
        'details' => 'array',
        'is_active' => 'boolean',
    ];

    protected $appends = ['statement_source_name'];

    /** Las monedas en que se registra un método (documento de Fran del 2026-10-06, §2). */
    public const CURRENCIES = ['VES', 'USD', 'USDT', 'EUR'];

    /**
     * Los métodos digitales que puede tener una cuenta: los tipos de comprobante que distingue la IA (§2 del documento
     * de Fran: el método, la moneda y el extracto de cada cuenta se configuran aquí, no en el código).
     * @return array<string, string> código => nombre
     */
    public static function methods(): array
    {
        $kinds = array_diff(ReceiptReader::KINDS, ['efectivo', 'otro', 'ilegible']);

        return array_combine($kinds, array_map(fn ($k) => ReceiptChecker::KIND_LABEL[$k] ?? $k, $kinds));
    }

    public function statementSource()
    {
        return $this->belongsTo(StatementSource::class);
    }

    public function getStatementSourceNameAttribute(): ?string
    {
        return $this->statementSource?->name;
    }
}
