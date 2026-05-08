<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Supplier extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'contact_name',
        'phone',
        'email',
        'address',
        'currency',
        'default_lead_time_days',
        'notes',
        'is_active',
    ];

    protected $casts = [
        'is_active'              => 'boolean',
        'default_lead_time_days' => 'integer',
    ];

    /**
     * Purchase orders placed with this supplier
     */
    public function purchaseOrders()
    {
        return $this->hasMany(PurchaseOrder::class);
    }

    /**
     * Active scope
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
