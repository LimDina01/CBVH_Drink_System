<?php

namespace App\Console\Commands;

use App\Services\RecurringOrderService;
use Illuminate\Console\Command;

class DispatchRecurringOrders extends Command
{
    protected $signature = 'recurring:dispatch {--date= : The date to dispatch for (YYYY-MM-DD)}';

    protected $description = 'Dispatch active recurring standing orders into live barista tickets';

    public function handle(RecurringOrderService $service): int
    {
        $date = $this->option('date') ? \Carbon\Carbon::parse($this->option('date')) : today();
        $this->info("Dispatching recurring orders for {$date->toDateString()}...");

        $orders = $service->dispatchDueOrders($date);

        $count = count($orders);
        $this->info("Dispatched {$count} recurring order(s).");

        return self::SUCCESS;
    }
}
