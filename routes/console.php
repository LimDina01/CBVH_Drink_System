<?php

use App\Models\DrinkOrder;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('drinks:auto-complete', function () {
    $count = DrinkOrder::autoCompleteExpiredDeliveries();
    $this->info("Auto-completed {$count} expired drink deliveries.");
})->purpose('Auto-complete drink orders in delivery after 10 minutes');

Schedule::command('drinks:auto-complete')->everyMinute();
