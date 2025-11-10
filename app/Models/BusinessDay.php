<?php
// app/Models/BusinessDay.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class BusinessDay extends Model
{
    use HasFactory;

    protected $fillable = ['date', 'open_at', 'close_at', 'opened_by', 'closed_by'];

    protected $casts = [
        'open_at'  => 'datetime',
        'close_at' => 'datetime',
        'date'     => 'date',
    ];

    public function opener()
    {
        return $this->belongsTo(User::class, 'opened_by');
    }
    public function closer()
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function getIsOpenAttribute(): bool
    {
        return !is_null($this->open_at) && is_null($this->close_at);
    }
}
