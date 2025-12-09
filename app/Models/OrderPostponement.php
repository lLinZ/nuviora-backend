<?php
// app/Models/OrderPostponement.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class OrderPostponement extends Model
{
    use HasFactory;

    protected $fillable = ['order_id', 'user_id', 'reason', 'scheduled_for'];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
