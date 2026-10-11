<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Una versión del archivo de cierre (§23, §46). Reemplazarlo deja la anterior guardada (§40). */
class AgencyClosureFile extends Model
{
    public $timestamps = false;

    protected $fillable = ['closure_id', 'version', 'path', 'original_name', 'mime', 'size', 'is_current', 'extraction', 'rows_count', 'reported_total', 'uploaded_by', 'uploaded_at'];

    protected $casts = [
        'is_current' => 'boolean',
        'extraction' => 'array',
        'reported_total' => 'float',
        'uploaded_at' => 'datetime',
    ];

    public function closure()
    {
        return $this->belongsTo(AgencyWeeklyClosure::class, 'closure_id');
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function extension(): string
    {
        return strtolower(pathinfo($this->original_name, PATHINFO_EXTENSION));
    }
}
