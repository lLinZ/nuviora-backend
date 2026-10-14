<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Una cuenta de la caja (Módulo 4 de Fran, §35 y §36): dónde está el dinero. El saldo empresarial inicial es la parte del
 * negocio (§36); las cuentas "Efectivo pendiente — Agencia X" (§42) se crean solas, una por agencia y moneda.
 */
class FinancialAccount extends Model
{
    public const BANK = 'bank';
    public const WALLET = 'wallet';
    public const CASH = 'cash';
    public const AGENCY_CASH = 'agency_cash';
    public const OTHER = 'other';

    public const KINDS = [
        self::BANK => 'Banco', self::WALLET => 'Billetera digital', self::CASH => 'Efectivo',
        self::AGENCY_CASH => 'Efectivo pendiente de agencia', self::OTHER => 'Otra',
    ];

    public const CURRENCIES = ['VES', 'USD', 'USDT', 'EUR'];

    protected $fillable = ['name', 'kind', 'currency', 'agency_id', 'opening_balance', 'opening_date', 'is_active', 'notes', 'created_by'];

    protected $casts = [
        'opening_balance' => 'float',
        'opening_date' => 'date:Y-m-d',
        'is_active' => 'boolean',
    ];

    public function agency()
    {
        return $this->belongsTo(User::class, 'agency_id');
    }

    public function companyAccounts()
    {
        return $this->hasMany(CompanyAccount::class);
    }

    public function transactions()
    {
        return $this->hasMany(FinancialTransaction::class);
    }

    public function isAgencyCash(): bool
    {
        return $this->kind === self::AGENCY_CASH;
    }

    /** Lo que se audita. */
    public function snapshot(): array
    {
        return [
            'name' => $this->name,
            'kind' => $this->kind,
            'currency' => $this->currency,
            'opening_balance' => $this->opening_balance,
            'opening_date' => $this->opening_date?->format('Y-m-d'),
            'is_active' => $this->is_active,
            'notes' => $this->notes,
        ];
    }
}
