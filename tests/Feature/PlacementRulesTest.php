<?php

use App\Models\Container;
use App\Services\PlacementRules;
use App\Services\RowGrid;

beforeEach(function () {
    seedLayout();
    $this->rules = app(PlacementRules::class);
    $this->a = row('Lager 1 · A');
});

it('allows every floor slot in an empty row', function () {
    $targets = RowGrid::load($this->a)->legalTargets();

    expect($targets)->toHaveCount(10)
        ->and(collect($targets)->every(fn ($t) => $t[1] === 1))->toBeTrue();
});

it('only allows the top of the front column, and the empty columns in front of it', function () {
    fillColumns($this->a, [10, 9, 8]);
    putAt($this->a, 7, 1);

    $targets = RowGrid::load($this->a)->legalTargets();

    expect($targets)->toEqual([[1, 1], [2, 1], [3, 1], [4, 1], [5, 1], [6, 1], [7, 2]]);
});

it('does not offer a full front column', function () {
    fillColumns($this->a, [10]);

    expect(RowGrid::load($this->a)->legalTargets())->not->toContain([10, 4]);
    expect(RowGrid::load($this->a)->legalTargets())->toHaveCount(9);
});

it('rejects slots behind a container', function () {
    putAt($this->a, 5, 1);

    expect($this->rules->whyCannotPlace(slot($this->a, 6, 1)))->toBe('Der står containere foran pladsen.');
});

it('rejects floating slots', function () {
    expect($this->rules->whyCannotPlace(slot($this->a, 10, 2)))->toBe('Der står ingen container under pladsen.');
});

it('rejects occupied and non-existing slots', function () {
    putAt($this->a, 10, 1);

    expect($this->rules->whyCannotPlace(slot($this->a, 10, 1)))->toBe('Pladsen er optaget.')
        ->and($this->rules->whyCannotPlace(slot($this->a, 11, 1)))->toBe('Pladsen findes ikke i rækken.')
        ->and($this->rules->whyCannotPlace(slot($this->a, 1, 4)))->toBe('Pladsen findes ikke i rækken.');
});

it('only lets you take the top container of the front column', function () {
    fillColumns($this->a, [10, 9]);

    $top = Container::query()->where(['storage_row_id' => $this->a->id, 'depth' => 9, 'level' => 3])->first();
    $below = Container::query()->where(['storage_row_id' => $this->a->id, 'depth' => 9, 'level' => 2])->first();
    $behind = Container::query()->where(['storage_row_id' => $this->a->id, 'depth' => 10, 'level' => 3])->first();

    expect($this->rules->canTake($top))->toBeTrue()
        ->and($this->rules->whyCannotTake($below))->toBe('Containeren er spærret af 1 container.')
        ->and($this->rules->whyCannotTake($behind))->toBe('Containeren er spærret af 3 containere.')
        ->and(RowGrid::load($this->a)->takeable()->id)->toBe($top->id);
});

it('treats the lifted container as gone when finding targets in its own row', function () {
    putAt($this->a, 10, 1);
    $c = putAt($this->a, 9, 1);

    $targets = $this->rules->legalTargets($this->a, ignoreContainerId: $c->id);

    expect(collect($targets)->map->key()->all())->toContain(slot($this->a, 10, 2)->key());
});

it('warns about empty slots that get blocked behind a placement', function () {
    fillColumns($this->a, [10]);
    putAt($this->a, 9, 1);

    // Sætter man i kolonne 7, spærres kolonne 8 (3 tomme) og de 2 ledige oven på kolonne 9.
    expect($this->rules->blockedBehind(slot($this->a, 7, 1)))->toBe(5)
        ->and($this->rules->warnings(slot($this->a, 7, 1)))->toBe(['Du spærrer 5 tomme pladser bag ved.'])
        ->and($this->rules->blockedBehind(slot($this->a, 9, 2)))->toBe(0)
        ->and($this->rules->blockedBehind(slot($this->a, 8, 1)))->toBe(2);
});

it('counts the whole empty row behind a placement at the entrance', function () {
    expect($this->rules->blockedBehind(slot($this->a, 1, 1)))->toBe(27);
});

it('treats buffer stacks as rows of depth one', function () {
    $buffer = row('Lager 1 · Buffer 1');
    putAt($buffer, 1, 1);

    expect(RowGrid::load($buffer)->legalTargets())->toBe([[1, 2]])
        ->and($this->rules->blockedBehind(slot($buffer, 1, 2)))->toBe(0);
});
