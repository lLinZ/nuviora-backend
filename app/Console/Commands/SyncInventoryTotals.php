<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Inventory;

class SyncInventoryTotals extends Command
{
    protected $signature = 'inventory:sync-totals';
    protected $description = 'Sync inventory quantity field with the sum of sizes_stock JSON';

    public function handle()
    {
        $inventories = Inventory::all();
        $this->info("Checking " . $inventories->count() . " inventory records...");

        foreach ($inventories as $inv) {
            if (empty($inv->sizes_stock)) {
                continue;
            }

            $totalFromSizes = array_sum($inv->sizes_stock);
            
            if ($inv->quantity != $totalFromSizes) {
                $this->warn("Mismatch for ID {$inv->id} (Product: {$inv->product_id}, Warehouse: {$inv->warehouse_id}): DB Quantity: {$inv->quantity} | Sum of Sizes: {$totalFromSizes}");
                $inv->quantity = $totalFromSizes;
                $inv->save();
                $this->info("Fixed.");
            }
        }

        $this->info("Sync completed.");
    }
}
