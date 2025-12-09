<?php
// app/Console/Commands/AssignBacklog.php
namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\Assignment\AssignOrderService;

class AssignBacklog extends Command
{
    protected $signature = 'orders:assign-backlog {--from=} {--to=}';
    protected $description = 'Asigna órdenes sin agente en un rango de tiempo';

    public function handle()
    {
        $from = $this->option('from') ? now()->parse($this->option('from')) : now()->startOfDay();
        $to   = $this->option('to')   ? now()->parse($this->option('to'))   : now();

        $count = app(AssignOrderService::class)->assignBacklog($from, $to);
        $this->info("Asignadas: {$count}");
        return 0;
    }
}
