<?php
// app/Models/DelivererStock.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class DelivererStock extends Model
{
    use HasFactory;

    protected $fillable = ['date', 'deliverer_id', 'status'];

    public function deliverer()
    {
        return $this->belongsTo(User::class, 'deliverer_id');
    }
    public function items()
    {
        return $this->hasMany(DelivererStockItem::class);
    }
}
