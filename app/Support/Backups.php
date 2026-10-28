<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;

/** Kopier af SQLite-databasen i storage/backups. */
final class Backups
{
    public const KEEP = 30;

    public static function dir(): string
    {
        return storage_path('backups');
    }

    /** Tager en konsistent kopi med SQLites VACUUM INTO og rydder gamle kopier op. */
    public static function run(): string
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            throw new RuntimeException('Backup understøtter kun SQLite. Brug databasens egen backup.');
        }

        File::ensureDirectoryExists(self::dir());

        $file = self::dir().DIRECTORY_SEPARATOR.'lager-'.now()->format('Y-m-d_His').'.sqlite';
        DB::statement('VACUUM INTO ?', [$file]);

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

        $files = collect(File::files(self::dir()))
            ->filter(fn ($f) => str_ends_with($f->getFilename(), '.sqlite'))
            ->map(fn ($f) => ['name' => $f->getFilename(), 'size' => $f->getSize(), 'time' => $f->getMTime()])
            ->sortByDesc('name')
            ->values()
            ->all();

        return $files;
    }

    public static function path(string $name): ?string
    {
        $name = basename($name);
        $path = self::dir().DIRECTORY_SEPARATOR.$name;

        return str_ends_with($name, '.sqlite') && is_file($path) ? $path : null;
    }
}
