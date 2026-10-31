<?php

use Illuminate\Support\Facades\Schedule;

/*
| Background jobs. On Hostinger, one Cron Job runs `php artisan schedule:run` every minute,
| and Laravel starts each job below at the right time.
*/
// After each GitHub deploy: database changes, first admin (does nothing when there's nothing to do).
Schedule::command('topline:deploy')->everyMinute()->withoutOverlapping();
Schedule::command('topline:payments-sweep')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('topline:billing-sweep')->dailyAt('06:15')->timezone('Africa/Nairobi');
