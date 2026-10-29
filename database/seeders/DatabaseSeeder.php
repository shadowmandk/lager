<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Layout og admin køres altid. Demodata kun lokalt eller med SEED_DEMO=true.
     */
    public function run(): void
    {
        $this->call([LayoutSeeder::class, AdminSeeder::class, AccessCodeSeeder::class]);

        if (app()->isLocal() || env('SEED_DEMO')) {
            $this->call([DemoSeeder::class, DemoHistorySeeder::class]);
        }
    }
}
