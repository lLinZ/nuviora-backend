<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Producto disponible en una ciudad, Sí/No (Módulo 5 de Fran, §12, §122). Sin fila = disponible. */
class ProductCityAvailability extends Model
{
    protected $table = 'product_city_availability';

    protected $fillable = ['product_id', 'city_id', 'is_available', 'updated_by'];

    protected $casts = ['is_available' => 'boolean'];
}
