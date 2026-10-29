<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/*
|--------------------------------------------------------------------------
| Hjælpere til lagertests
|--------------------------------------------------------------------------
*/

function seedLayout(): void
{
    (new Database\Seeders\LayoutSeeder)->run();
}

function row(string $label): App\Models\StorageRow
{
    return App\Models\StorageRow::query()->with('zone.warehouse')->get()
        ->first(fn ($r) => $r->label() === $label)
        ?? throw new RuntimeException("Ukendt række {$label}");
}

function putAt(App\Models\StorageRow $row, int $depth, int $level, array $attrs = []): App\Models\Container
{
    static $n = 1;

    return App\Models\Container::query()->create($attrs + [
        'number' => 'T'.$n++,
        'status' => App\Models\Container::STORED,
        'storage_row_id' => $row->id,
        'depth' => $depth,
        'level' => $level,
    ]);
}

/** Fyld kolonner i en række helt op. */
function fillColumns(App\Models\StorageRow $row, array $depths): void
{
    foreach ($depths as $depth) {
        foreach (range(1, $row->height) as $level) {
            putAt($row, $depth, $level);
        }
    }
}

function actor(): App\Services\Actor
{
    $op = App\Models\Operator::query()->firstOrCreate(['name' => 'Test']);

    return App\Services\Actor::operator($op);
}

function slot(App\Models\StorageRow $row, int $depth, int $level): App\Support\Slot
{
    return new App\Support\Slot($row->id, $depth, $level);
}

/** Session for en enhed, der er låst op med koden til låseskærmen. */
function unlocked(array $session = []): array
{
    return $session + ['unlocked' => [
        'v' => (int) App\Models\Setting::get('access.version'),
        'seen' => now()->getTimestamp(),
    ]];
}
