<?php

use App\Models\Container;
use App\Models\Customer;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;

// Skiftet går fra SQLite til MySQL, så testen kører kun mod MySQL (i GitHub Actions).
it('copies everything from an old SQLite file into MySQL', function () {
    $file = tempnam(sys_get_temp_dir(), 'lager-sqlite-');
    config(['database.connections.kilde' => ['driver' => 'sqlite', 'database' => $file, 'prefix' => '', 'foreign_key_constraints' => true]]);
    $this->artisan('migrate', ['--database' => 'kilde', '--force' => true])->assertSuccessful();

    $old = DB::connection('kilde');
    $old->table('customers')->insert(['id' => 7, 'number' => 'K7', 'name' => 'Ærø Fragt', 'color' => 4, 'created_at' => now(), 'updated_at' => now()]);
    $old->table('containers')->insert(['id' => 40, 'number' => '4040', 'customer_id' => 7, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
    $old->table('settings')->insert(['key' => 'access.code_hash', 'value' => 'hash']);
    $old->table('sessions')->insert(['id' => 'x', 'payload' => 'y', 'last_activity' => 1]);
    DB::purge('kilde');

    Customer::query()->create(['number' => 'SLET', 'name' => 'Erstattes']);

    try {
        $this->artisan('lager:fra-sqlite', ['fil' => $file, '--force' => true])->assertSuccessful();
    } finally {
        @unlink($file);
    }

    expect(Customer::query()->pluck('name')->all())->toBe(['Ærø Fragt'])
        ->and(Customer::query()->find(7)->color)->toBe(4)
        ->and(Container::query()->find(40)->customer->name)->toBe('Ærø Fragt')
        ->and(Setting::get('access.code_hash'))->toBe('hash')
        ->and(DB::table('sessions')->where('id', 'x')->exists())->toBeFalse();

    // Nye rækker fortsætter efter de kopierede id'er.
    expect(Customer::query()->create(['number' => 'K8', 'name' => 'Ny'])->id)->toBeGreaterThan(7);
})->skip(fn () => DB::connection()->getDriverName() !== 'mysql', 'Kun mod MySQL');

it('refuses to copy into SQLite', function () {
    $this->artisan('lager:fra-sqlite', ['fil' => __FILE__, '--force' => true])
        ->expectsOutputToContain('stadig SQLite')
        ->assertFailed();
})->skip(fn () => DB::connection()->getDriverName() !== 'sqlite', 'Kun mod SQLite');
