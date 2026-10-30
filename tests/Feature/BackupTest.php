<?php

use App\Support\Backups;
use Illuminate\Support\Facades\DB;

// SQLite kan ikke tage VACUUM INTO midt i testens transaktion, så kun MySQL-udgaven testes her (i GitHub Actions).
it('dumps the MySQL database to a compressed file', function () {
    $file = Backups::run();

    try {
        expect($file)->toEndWith('.sql.gz')
            ->and(Backups::path(basename($file)))->toBe($file)
            ->and(collect(Backups::all())->pluck('name'))->toContain(basename($file))
            ->and(gzdecode(file_get_contents($file)))->toContain('CREATE TABLE `containers`');
    } finally {
        @unlink($file);
    }
})->skip(fn () => DB::connection()->getDriverName() !== 'mysql', 'Kun mod MySQL');

it('only hands out backup files', function () {
    expect(Backups::path('../.env'))->toBeNull()
        ->and(Backups::path('lager-x.php'))->toBeNull();
});
