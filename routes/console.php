<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Tencia sync
|--------------------------------------------------------------------------
|
| Overnight so a planner arriving in the morning sees yesterday's close. The
| order book, invoiced history, stock on hand and closed production runs are
| read from Tencia when needed and are not copied locally. The product overlay
| and bills of material still refresh nightly so schedules have local SKU ids
| to point at.
|
| Each sync is idempotent, so an overlapping or repeated run is harmless.
| withoutOverlapping guards the long history loads regardless.
|
*/

Schedule::command('sync:products')->dailyAt('04:00')->withoutOverlapping();
Schedule::command('sync:bom')->dailyAt('04:15')->withoutOverlapping();
