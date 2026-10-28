<?php

use App\Services\Microbizz\MicrobizzClient;
use App\Services\Microbizz\MicrobizzException;
use App\Services\Microbizz\MicrobizzImporter;
use App\Support\Backups;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('backup:run', function () {
    $file = Backups::run();
    $this->info('Backup gemt: '.basename($file));
})->purpose('Tag en kopi af databasen til storage/backups');

Artisan::command('microbizz:sync', function (MicrobizzImporter $importer) {
    try {
        $run = $importer->run('schedule');
    } catch (MicrobizzException $e) {
        $this->error($e->getMessage());

        return 1;
    }

    if (! $run->isOk()) {
        $this->error($run->error);

        return 1;
    }

    $this->info($run->summary().'.');
})->purpose('Hent kunder og containere, der er ændret i Microbizz siden sidst');

Schedule::command('backup:run')->dailyAt('02:00');

// Hver time i arbejdstiden. Kører kun, når adgangsoplysningerne står i .env.
Schedule::command('microbizz:sync')
    ->hourly()
    ->between('6:00', '18:00')
    ->withoutOverlapping(30)
    ->when(fn () => app(MicrobizzClient::class)->isConfigured());
