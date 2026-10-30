<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/** Kopier af databasen i storage/backups: MySQL som komprimeret SQL-dump, SQLite (lokalt og i tests) som fil. */
final class Backups
{
    public const KEEP = 30;

    private const TYPES = ['.sql.gz', '.sqlite'];

    public static function dir(): string
    {
        return storage_path('backups');
    }

    /** Tager en konsistent kopi og rydder gamle kopier op. */
    public static function run(): string
    {
        File::ensureDirectoryExists(self::dir());
        $base = self::dir().DIRECTORY_SEPARATOR.'lager-'.now()->format('Y-m-d_His');

        $file = match (DB::connection()->getDriverName()) {
            'mysql', 'mariadb' => self::dumpMysql($base.'.sql.gz'),
            'sqlite' => self::copySqlite($base.'.sqlite'),
            default => throw new RuntimeException('Backup understøtter kun MySQL og SQLite.'),
        };

        foreach (array_slice(self::all(), self::KEEP) as $old) {
            File::delete(self::dir().DIRECTORY_SEPARATOR.$old['name']);
        }

        return $file;
    }

    /** @return list<array{name: string, size: int, time: int}> nyeste først */
    public static function all(): array
    {
        if (! is_dir(self::dir())) {
            return [];
        }

        return collect(File::files(self::dir()))
            ->filter(fn ($f) => self::isBackup($f->getFilename()))
            ->map(fn ($f) => ['name' => $f->getFilename(), 'size' => $f->getSize(), 'time' => $f->getMTime()])
            ->sortByDesc('name')
            ->values()
            ->all();
    }

    public static function path(string $name): ?string
    {
        $name = basename($name);
        $path = self::dir().DIRECTORY_SEPARATOR.$name;

        return self::isBackup($name) && is_file($path) ? $path : null;
    }

    private static function isBackup(string $name): bool
    {
        return str_starts_with($name, 'lager-') && collect(self::TYPES)->contains(fn ($t) => str_ends_with($name, $t));
    }

    private static function copySqlite(string $file): string
    {
        DB::statement('VACUUM INTO ?', [$file]);

        return $file;
    }

    /**
     * mysqldump med --single-transaction giver en konsistent kopi uden at låse tabellerne.
     * Adgangskoden lægges i en midlertidig fil, så den ikke kan ses i proceslisten.
     */
    private static function dumpMysql(string $file): string
    {
        $config = DB::connection()->getConfig();
        $sql = substr($file, 0, -3);
        $cnf = tempnam(sys_get_temp_dir(), 'lager-cnf-');
        chmod($cnf, 0600);

        $quote = fn ($v) => '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], (string) $v).'"';
        $lines = ['[client]', 'user='.$quote($config['username'] ?? ''), 'password='.$quote($config['password'] ?? '')];
        if (! empty($config['unix_socket'])) {
            $lines[] = 'socket='.$quote($config['unix_socket']);
        } else {
            $lines[] = 'host='.$quote($config['host'] ?? '127.0.0.1');
            $lines[] = 'port='.(int) ($config['port'] ?? 3306);
        }
        file_put_contents($cnf, implode("\n", $lines)."\n");

        try {
            $result = Process::timeout(600)->run([
                'mysqldump',
                '--defaults-extra-file='.$cnf,
                '--single-transaction',
                '--quick',
                '--no-tablespaces',
                '--default-character-set=utf8mb4',
                '--result-file='.$sql,
                $config['database'],
            ]);
        } finally {
            @unlink($cnf);
        }

        if (! $result->successful()) {
            @unlink($sql);

            throw new RuntimeException('Backup fejlede: '.trim($result->errorOutput()));
        }

        // Komprimér og fjern den ukomprimerede fil.
        $in = fopen($sql, 'rb');
        $out = gzopen($file, 'wb6');
        while (! feof($in)) {
            gzwrite($out, fread($in, 1 << 20));
        }
        fclose($in);
        gzclose($out);
        unlink($sql);

        return $file;
    }
}
