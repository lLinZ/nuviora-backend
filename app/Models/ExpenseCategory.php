<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Categoría de los gastos (Módulo 4 de Fran, §24, §25): para saber cuánto se gasta en cada cosa, por ejemplo transporte (§13). */
class ExpenseCategory extends Model
{
    protected $fillable = ['name', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];
}
