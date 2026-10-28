<?php

use App\Support\Backups;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('backup:run', function () {
    $file = Backups::run();
    $this->info('Backup gemt: '.basename($file));
})->purpose('Tag en kopi af databasen til storage/backups');

Schedule::command('backup:run')->dailyAt('02:00');
