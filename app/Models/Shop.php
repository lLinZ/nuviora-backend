<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Shop extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'shopify_domain',
        'shopify_access_token',
        'shopify_webhook_secret',
        'status_id',
        'auto_open_at',
        'auto_close_at',
        'auto_schedule_enabled',
    ];

    protected $casts = [
        'auto_schedule_enabled' => 'boolean',
    ];

    public function status()
    {
        return $this->belongsTo(Status::class);
    }

    public function sellers()
    {
        return $this->belongsToMany(User::class, 'shop_user')->withPivot('is_default_roster');
    }

    public function orders()
    {
        return $this->hasMany(Order::class);
    }
}
