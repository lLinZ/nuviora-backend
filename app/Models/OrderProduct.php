<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class OrderProduct extends Model // 👈 mejor singular
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'product_id',
        'variant_id',
        'product_number',
        'title',
        'name',
        'showable_name',
        'price',
        'quantity',
        'image',
        'description',
        'size',
        'is_upsell',
        'upsell_user_id'
    ];

    protected static function booted()
    {
        // La variante manda (tarea 4): `size` guarda su nombre para leerlo. Una línea que llega solo con el
        // nombre de la talla se enlaza a la variante del catálogo que se llame así, si existe.
        static::saving(function (OrderProduct $line) {
            if ($line->variant_id && ($line->isDirty('variant_id') || $line->isDirty('product_id'))) {
                $variant = ProductVariant::find($line->variant_id);
                if (!$variant || (int) $variant->product_id !== (int) $line->product_id) {
                    $line->variant_id = null;
                } else {
                    $line->size = $variant->title;
                }
            }
            if (!$line->variant_id && trim((string) $line->size) !== '' && ($line->isDirty('size') || $line->isDirty('product_id'))) {
                $line->variant_id = ProductVariant::where('product_id', $line->product_id)
                    ->where('key', ProductVariant::keyFor($line->size))->value('id');
            }
        });
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function variant()
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function upsellUser()
    {
        return $this->belongsTo(User::class, 'upsell_user_id');
    }
}
