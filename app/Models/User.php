<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use App\Models\Order;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable, HasApiTokens;

    /**
     * Get the status that owns the user.
     */
    public function status()
    {
        return $this->belongsTo(Status::class, 'status_id');
    }
    /**
     * Get the role that owns the user.
     */
    public function role()
    {
        return $this->belongsTo(Role::class, 'role_id');
    }

    public function shops()
    {
        return $this->belongsToMany(Shop::class, 'shop_user');
    }
    
    /**
     * Get the warehouse linked to this user (for Repartidor or Agencia)
     */
    public function warehouse()
    {
        return $this->hasOne(Warehouse::class);
    }

    public function agentOrders()
    {
        return $this->hasMany(Order::class, 'agent_id');
    }

    public function delivererOrders()
    {
        return $this->hasMany(Order::class, 'deliverer_id');
    }

    public function agencyOrders()
    {
        return $this->hasMany(Order::class, 'agency_id');
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'names',
        'surnames',
        'phone',
        'address',
        'theme',
        'color',
        'email',
        'password',
        'role_id',
        'status_id',
        'agency_id',
        'delivery_cost'
    ];

    public function deliverers()
    {
        return $this->hasMany(User::class, 'agency_id');
    }

    public function agency()
    {
        return $this->belongsTo(User::class, 'agency_id');
    }

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
