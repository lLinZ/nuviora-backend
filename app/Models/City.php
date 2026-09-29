<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class City extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'delivery_cost_usd',
        'agency_id',
    ];

    /** Agencia principal (la primera del reparto). El reparto usa agencies(). */
    public function agency()
    {
        return $this->belongsTo(User::class, 'agency_id');
    }

    /** Agencias que entregan en esta ciudad, con su % (null = parejo) y si están activas (tarea 3c). */
    public function agencies()
    {
        return $this->belongsToMany(User::class, 'city_agency', 'city_id', 'agency_id')
            ->withPivot(['weight', 'is_active'])
            ->withTimestamps();
    }

    public function orders()
    {
        return $this->hasMany(Order::class);
    }
}
