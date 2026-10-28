<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

/** Opretter den første admin ud fra ADMIN_NAME, ADMIN_EMAIL og ADMIN_PASSWORD i .env. */
class AdminSeeder extends Seeder
{
    public function run(): void
    {
        $email = env('ADMIN_EMAIL');
        $password = env('ADMIN_PASSWORD');

        if (! $email || ! $password) {
            $this->command?->warn('ADMIN_EMAIL eller ADMIN_PASSWORD mangler i .env. Ingen admin oprettet.');

            return;
        }

        User::query()->firstOrCreate(
            ['email' => $email],
            ['name' => env('ADMIN_NAME', 'Administrator'), 'password' => $password],
        );
    }
}
