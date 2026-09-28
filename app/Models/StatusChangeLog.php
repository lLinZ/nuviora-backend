<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Cambios de estado de las órdenes, para leer (métricas). order_status_logs dejó de escribirse el
 * 28-feb-2026 (commit eba2010); desde el 11-feb existe order_tracking_comprehensive_logs, que además
 * registra las reasignaciones. Esta vista une las dos: hasta CUTOFF, la tabla vieja; desde ahí, la nueva
 * y solo sus filas que son cambios de estado (o la creación de la orden). Mismas columnas que antes.
 */
class StatusChangeLog extends Model
{
    public const CUTOFF = '2026-02-12 00:00:00';

    protected $table = 'status_change_logs';

    protected static function booted(): void
    {
        static::addGlobalScope('union', function (Builder $query) {
            $columns = ['order_id', 'from_status_id', 'to_status_id', 'user_id', 'created_at', 'updated_at'];
            $old = DB::table('order_status_logs')->select($columns)->where('created_at', '<', self::CUTOFF);
            $new = DB::table('order_tracking_comprehensive_logs')->select($columns)
                ->where('created_at', '>=', self::CUTOFF)
                ->where(fn ($q) => $q->whereNull('from_status_id')->orWhereColumn('from_status_id', '!=', 'to_status_id'));
            $query->getQuery()->fromSub($old->unionAll($new), 'status_change_logs');
        });
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function fromStatus()
    {
        return $this->belongsTo(Status::class, 'from_status_id');
    }

    public function toStatus()
    {
        return $this->belongsTo(Status::class, 'to_status_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
