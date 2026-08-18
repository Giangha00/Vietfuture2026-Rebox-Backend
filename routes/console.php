<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| AI auto-retrain pipeline (weekly)
|--------------------------------------------------------------------------
| Export approved samples → LoRA train → eval gate → promote or rollback.
| Requires either:
|   php artisan schedule:work
| or cron: * * * * * cd /path/to/rebox-backend && php artisan schedule:run
|
| Manual run:
|   php artisan ai:run-retrain-pipeline --force
*/
Schedule::command('ai:run-retrain-pipeline')
    ->weeklyOn(0, '03:00')
    ->name('ai-run-retrain-pipeline')
    ->withoutOverlapping(360)
    ->appendOutputTo(storage_path('logs/ai-retrain.log'));
