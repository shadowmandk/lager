<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Flytter alle data fra den gamle SQLite-fil over i MySQL ved skiftet: lagre, containere, kunder, log,
 * medarbejdere, admin-login, indstillinger og kode til låseskærmen. Sessioner og cache flyttes ikke.
 */
class ImportFromSqlite extends Command
{
    protected $signature = 'lager:fra-sqlite {fil : Stien til den gamle database.sqlite} {--force : Spørg ikke først}';

    protected $description = 'Kopiér alle data fra en SQLite-fil over i den nuværende database (MySQL)';

    /** Midlertidige data, der ikke skal med. */
    private const SKIP = ['migrations', 'sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'password_reset_tokens'];

    public function handle(): int
    {
        $file = (string) $this->argument('fil');

        if (! is_file($file)) {
            $this->error("Filen {$file} findes ikke.");

            return self::FAILURE;
        }

        $target = DB::connection();

        if ($target->getDriverName() === 'sqlite') {
            $this->error('Databasen i .env er stadig SQLite. Sæt DB_CONNECTION=mysql først.');

            return self::FAILURE;
        }

        config(['database.connections.fra_sqlite' => [
            'driver' => 'sqlite',
            'database' => $file,
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]]);
        $source = DB::connection('fra_sqlite');

        $sourceMigrations = $source->table('migrations')->pluck('migration')->all();
        $missing = array_diff($sourceMigrations, $target->table('migrations')->pluck('migration')->all());

        if ($missing !== []) {
            $this->error('MySQL mangler databaseændringer, som SQLite-filen har. Kør "php artisan migrate --force" først.');

            return self::FAILURE;
        }

        $tables = collect($source->select("select name from sqlite_master where type = 'table' and name not like 'sqlite_%'"))
            ->pluck('name')
            ->reject(fn ($t) => in_array($t, self::SKIP, true) || ! Schema::hasTable($t))
            ->values();

        if (! $this->option('force') && ! $this->confirm('Alle data i '.$target->getDatabaseName().' erstattes med indholdet af '.basename($file).'. Fortsæt?')) {
            return self::FAILURE;
        }

        $counts = [];

        Schema::disableForeignKeyConstraints();

        try {
            $target->transaction(function () use ($tables, $source, $target, &$counts) {
                foreach ($tables as $table) {
                    $columns = array_values(array_intersect(
                        $source->getSchemaBuilder()->getColumnListing($table),
                        Schema::getColumnListing($table),
                    ));

                    $target->table($table)->delete();
                    $counts[$table] = 0;

                    $query = $source->table($table)->select($columns);
                    $ordered = in_array('id', $columns, true) ? $query->orderBy('id') : $query->orderBy($columns[0]);

                    $ordered->chunk(500, function ($rows) use ($target, $table, &$counts) {
                        $target->table($table)->insert($rows->map(fn ($r) => (array) $r)->all());
                        $counts[$table] += $rows->count();
                    });
                }
            });
        } finally {
            Schema::enableForeignKeyConstraints();
        }

        $this->table(['Tabel', 'Rækker'], collect($counts)->map(fn ($n, $t) => [$t, $n])->values()->all());
        $this->info('Færdig. Alle data er flyttet til '.$target->getDatabaseName().'.');

        return self::SUCCESS;
    }
}
