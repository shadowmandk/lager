<?php

use App\Services\LayoutService;

beforeEach(function () {
    seedLayout();
    $this->layout = app(LayoutService::class);
    $this->a = row('Lager 1 · A');
});

it('refuses to shrink a row when containers stand on the removed slots', function () {
    putAt($this->a, 10, 1, ['number' => '9001']);

    $this->layout->updateRow($this->a, 'A', 8, 3);
})->throws(RuntimeException::class, '9001');

it('refuses to lower a row when containers stand on the removed levels', function () {
    putAt($this->a, 10, 1);
    putAt($this->a, 10, 2);
    putAt($this->a, 10, 3, ['number' => '9003']);

    $this->layout->updateRow($this->a, 'A', 10, 2);
})->throws(RuntimeException::class, '9003');

it('shrinks and grows a row when the removed slots are empty', function () {
    putAt($this->a, 5, 1);

    $this->layout->updateRow($this->a, 'A', 6, 4);

    expect($this->a->fresh()->depth)->toBe(6)->and($this->a->fresh()->height)->toBe(4);
});

it('only deletes rows, zones and warehouses without containers', function () {
    putAt($this->a, 10, 1);

    expect(fn () => $this->layout->deleteRow($this->a))->toThrow(RuntimeException::class)
        ->and(fn () => $this->layout->deleteZone($this->a->zone))->toThrow(RuntimeException::class)
        ->and(fn () => $this->layout->deleteWarehouse($this->a->zone->warehouse))->toThrow(RuntimeException::class);

    $empty = row('Lager 2 · A');
    $this->layout->deleteRow($empty);

    expect(App\Models\StorageRow::query()->find($empty->id))->toBeNull();
});
