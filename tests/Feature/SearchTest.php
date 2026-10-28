<?php

use App\Models\Container;
use App\Models\Customer;

beforeEach(function () {
    $cust = Customer::query()->create(['number' => '10021', 'name' => 'Jensen Byg']);
    Container::query()->create(['number' => '4711', 'name' => 'Værktøj', 'customer_id' => $cust->id, 'status' => Container::DELIVERED]);
    Container::query()->create(['number' => '5000', 'name' => 'Arkiv', 'status' => Container::PENDING]);
});

it('finds containers by number, name, customer name and customer number', function (string $term, array $expected) {
    expect(Container::query()->search($term)->pluck('number')->all())->toBe($expected);
})->with([
    'nummer' => ['471', ['4711']],
    'navn' => ['arkiv', ['5000']],
    'kundenavn' => ['jensen', ['4711']],
    'kundenummer' => ['10021', ['4711']],
    'tom søgning' => ['', ['4711', '5000']],
    'ingen træf' => ['zzz', []],
]);
