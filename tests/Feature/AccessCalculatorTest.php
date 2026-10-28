<?php

use App\Models\Container;
use App\Models\Setting;
use App\Services\AccessCalculator;

beforeEach(function () {
    seedLayout();
    $this->calc = app(AccessCalculator::class);
    $this->a = row('Lager 1 · A');
});

it('needs 29 moves to reach the innermost bottom container in a full row', function () {
    fillColumns($this->a, range(1, 10));
    $inner = Container::query()->where(['storage_row_id' => $this->a->id, 'depth' => 10, 'level' => 1])->first();

    $result = $this->calc->for($inner);

    expect($result['moves'])->toBe(29)
        ->and($result['minutes'])->toBe(29 * 4);
});

it('lists blockers in the order they can be taken', function () {
    fillColumns($this->a, [10]);
    putAt($this->a, 9, 1);
    putAt($this->a, 9, 2);
    $target = Container::query()->where(['storage_row_id' => $this->a->id, 'depth' => 10, 'level' => 2])->first();

    $order = collect($this->calc->for($target)['blockers'])->map(fn ($c) => [$c->depth, $c->level])->all();

    expect($order)->toBe([[9, 2], [9, 1], [10, 3]]);
});

it('is free to reach the takeable container', function () {
    $c = putAt($this->a, 10, 1);

    expect($this->calc->for($c)['moves'])->toBe(0);
});

it('uses the configured minutes per move', function () {
    Setting::put(Setting::MINUTES_PER_MOVE, '6');
    putAt($this->a, 10, 1);
    $c = Container::query()->first();
    putAt($this->a, 10, 2);

    expect($this->calc->for($c)['minutes'])->toBe(6);
});

it('reports free parking capacity in the buffer and the rest of the warehouse', function () {
    putAt(row('Lager 1 · Buffer 1'), 1, 1);
    fillColumns(row('Lager 1 · B'), [10]);
    $c = putAt($this->a, 10, 1);

    $result = $this->calc->for($c);

    expect($result['buffer_free'])->toBe(11)
        ->and($result['warehouse_free'])->toBe(27 + 30);
});

it('returns nothing for containers that are not on the board', function () {
    $c = Container::query()->create(['number' => 'U1', 'status' => Container::DELIVERED]);

    expect($this->calc->for($c))->toBeNull();
});
