<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Grabación o archivo de una reunión de la Líder con su equipo (spec §14). */
class MeetingRecord extends Model
{
    public const TYPES = ['grupal' => 'Capacitación grupal', 'uno_a_uno' => 'Reunión uno a uno'];

    protected $fillable = [
        'sales_group_id', 'created_by', 'meeting_type', 'meeting_date', 'title', 'notes',
        'file_path', 'file_name', 'url', 'consent_confirmed',
    ];

    protected $casts = [
        'meeting_date' => 'date:Y-m-d',
        'consent_confirmed' => 'boolean',
    ];

    public function sellers()
    {
        return $this->belongsToMany(User::class, 'meeting_record_sellers', 'meeting_record_id', 'seller_id');
    }

    public function author()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function toPayload(): array
    {
        return [
            'id' => $this->id,
            'meeting_type' => $this->meeting_type,
            'type_label' => self::TYPES[$this->meeting_type] ?? $this->meeting_type,
            'meeting_date' => $this->meeting_date?->toDateString(),
            'title' => $this->title,
            'notes' => $this->notes,
            'has_file' => (bool) $this->file_path,
            'file_name' => $this->file_name,
            'url' => $this->url,
            'consent_confirmed' => $this->consent_confirmed,
            'sellers' => $this->sellers->map(fn (User $u) => ['id' => $u->id, 'name' => trim($u->names . ' ' . $u->surnames)])->values(),
            'author' => trim(($this->author?->names ?? '') . ' ' . ($this->author?->surnames ?? '')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
