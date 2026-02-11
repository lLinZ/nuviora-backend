<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Shop;
use App\Models\BusinessDay;
use App\Services\Business\BusinessService;
use Illuminate\Support\Facades\Log;

class CheckShopSchedule extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'shops:check-schedule';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check and execute shop auto-open/close schedules';

    /**
     * Execute the console command.
     */
    public function handle(BusinessService $businessService)
    {
        $nowTime = now()->format('H:i');
        $today = now()->toDateString();

        $shops = Shop::where('auto_schedule_enabled', true)->get();

        foreach ($shops as $shop) {
            $this->checkOpen($shop, $nowTime, $today, $businessService);
            $this->checkClose($shop, $nowTime, $today, $businessService);
        }
    }

    protected function checkOpen(Shop $shop, string $nowTime, string $today, BusinessService $service)
    {
        if (!$shop->auto_open_at) return;
        // Check if time is right (simple check: now >= open_at)
        // Note: we should avoid re-opening if already open, but service handles exception.
        // Better to check state first to avoid exception logs.
        
        // Trim seconds from DB time if present "08:00:00" -> "08:00"
        $openTime = substr($shop->auto_open_at, 0, 5);
        if ($nowTime < $openTime) return;

        // Check if already open today
        $day = BusinessDay::where('date', $today)->where('shop_id', $shop->id)->first();
        if ($day && $day->open_at) return; // Already opened

        try {
            $this->info("Opening shop {$shop->name}...");
            
            // 1. Activate Default Roster FIRST so we have active agents
            $service->activateDefaultRoster($shop->id);

            // 2. Open Shop and assign backlog (now with active agents)
            $service->openShop($shop->id, true); 
            
            Log::info("Auto-opened shop {$shop->id}: {$shop->name}");
        } catch (\Exception $e) {
            Log::error("Failed to auto-open shop {$shop->id}: " . $e->getMessage());
        }
    }

    protected function checkClose(Shop $shop, string $nowTime, string $today, BusinessService $service)
    {
        if (!$shop->auto_close_at) return;
        $closeTime = substr($shop->auto_close_at, 0, 5);
        if ($nowTime < $closeTime) return;

        // Check if open and not yet closed
        $day = BusinessDay::where('date', $today)->where('shop_id', $shop->id)->first();
        if (!$day || !$day->open_at) return; // Not open, can't close
        if ($day->close_at) return; // Already closed

        try {
            $this->info("Closing shop {$shop->name}...");
            $service->closeShop($shop->id);
            Log::info("Auto-closed shop {$shop->id}: {$shop->name}");
        } catch (\Exception $e) {
            Log::error("Failed to auto-close shop {$shop->id}: " . $e->getMessage());
        }
    }
}
