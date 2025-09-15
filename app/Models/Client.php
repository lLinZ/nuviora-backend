<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Client extends Model
{
    use HasFactory;
    protected $fillable = [
        'customer_id',
        'customer_number',
        'first_name',
        'last_name',
        'phone',
        'email',
        'country_name',
        'country_code',
        'province',
        'city',
        'address1',
        'address2',
    ];
}
