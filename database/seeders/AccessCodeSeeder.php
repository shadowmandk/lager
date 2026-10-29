<?php

namespace Database\Seeders;

use App\Support\DeviceLock;
use Illuminate\Database\Seeder;

/** Sætter koden til låseskærmen ud fra ACCESS_CODE i .env, hvis der ikke allerede er valgt en. */
class AccessCodeSeeder extends Seeder
{
    public function run(): void
    {
        if (DeviceLock::hasCode()) {
            return;
        }

        $code = (string) env('ACCESS_CODE');

        if (! DeviceLock::isValidCode($code)) {
            $this->command?->warn('ACCESS_CODE mangler eller er ikke '.DeviceLock::CODE_LENGTH.' cifre. Vælg koden med "php artisan lager:kode".');

            return;
        }

        DeviceLock::setCode($code);
    }
}
