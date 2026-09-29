<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Una variante de un producto (tarea 4): una talla, un color o la combinación ("Negro / M").
 * `key` es el nombre normalizado, sin espacios ni mayúsculas: "S/M", "s / m" y "Talla S/M" son la misma.
 */
class ProductVariant extends Model
{
    protected $fillable = [
        'product_id',
        'title',
        'key',
        'option1',
        'option2',
        'option3',
        'shopify_variant_id',
        'sku',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'shopify_variant_id' => 'integer',
    ];

    protected static function booted()
    {
        static::saving(function (ProductVariant $variant) {
            $variant->title = self::cleanTitle($variant->title);
            $variant->key = self::keyFor($variant->title);
            [$variant->option1, $variant->option2, $variant->option3] = self::optionsFrom($variant->title);
        });
    }

    /** Quita espacios de más y un "Talla:" al inicio (algunos formularios lo mandan así). */
    public static function cleanTitle(?string $title): string
    {
        $title = preg_replace('/^talla\s*:?\s*/iu', '', trim((string) $title));

        return trim(preg_replace('/\s+/u', ' ', $title));
    }

    public static function keyFor(?string $title): string
    {
        return mb_strtolower(preg_replace('/\s+/u', '', self::cleanTitle($title)));
    }

    /** Shopify separa las opciones con " / " ("Negro / M"). "S/M", sin espacios, es una sola talla. */
    public static function optionsFrom(string $title): array
    {
        return array_pad(array_slice(preg_split('/\s+\/\s+/u', $title), 0, 3), 3, null);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function stocks()
    {
        return $this->hasMany(InventoryVariant::class, 'variant_id');
    }
}
