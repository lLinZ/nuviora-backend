<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Nota privada de la Líder sobre una vendedora de su grupo (spec §13). */
class SellerNote extends Model
{
    protected $fillable = ['sales_group_id', 'seller_id', 'author_id', 'body'];

    public function author()
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function seller()
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function group()
    {
        return $this->belongsTo(SalesGroup::class, 'sales_group_id');
    }

    /** Forma común para la Líder y el Admin. */
    public function toPayload(): array
    {
        return [
            'id' => $this->id,
            'seller_id' => $this->seller_id,
            'body' => $this->body,
            'author' => trim(($this->author?->names ?? 'Sistema') . ' ' . ($this->author?->surnames ?? '')),
            'group' => $this->group?->name,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
