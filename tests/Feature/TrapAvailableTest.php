<?php

use App\Livewire\Board;
use App\Models\Customer;
use App\Models\Operator;
use App\Models\Warehouse;
use App\Services\PlacementRules;
use App\Services\RowGrid;
use App\Services\TransferPlanner;
use App\Services\WorkPlanner;
use Livewire\Livewire;

beforeEach(function () {
    seedLayout();
    $this->customer = Customer::query()->create(['number' => '1', 'name' => 'Jensen']);
    $this->a = row('Lager 1 · A');
    $this->b = row('Lager 1 · B');
    $this->c = row('Lager 1 · C');
});

/** En container med kunde, altså ikke ledig. */
function rentedAt(App\Models\StorageRow $row, int $depth, int $level, array $attrs = []): App\Models\Container
{
    return putAt($row, $depth, $level, $attrs + ['customer_id' => Customer::query()->value('id')]);
}

it('finds the available container that a placement would trap', function () {
    rentedAt($this->a, 10, 1);
    $free = putAt($this->a, 9, 1, ['number' => 'FRI']);

    $grid = RowGrid::load($this->a);

    expect($grid->trapsAvailable(9, 2)?->id)->toBe($free->id)
        ->and($grid->trapsAvailable(8, 1)?->id)->toBe($free->id)
        ->and($grid->trapsAvailable(1, 1)?->id)->toBe($free->id)
        ->and(RowGrid::load($this->b)->trapsAvailable(1, 1))->toBeNull();

    // Står der en med kunde oven på, er den ledige allerede spærret, og der advares ikke.
    rentedAt($this->a, 9, 2);

    expect(RowGrid::load($this->a)->trapsAvailable(9, 3))->toBeNull();
});

it('warns in the confirmation when a move traps an available container', function () {
    session(['operator_id' => Operator::query()->create(['name' => 'Bente'])->id]);
    putAt($this->b, 10, 1, ['number' => 'FRI']);
    $moving = rentedAt($this->a, 1, 1);

    $board = Livewire::test(Board::class)
        ->call('prepare', $moving->id, slot($this->a, 1, 1)->key(), slot($this->b, 10, 2)->key());

    expect($board->get('confirm')['warnings'])->toBe(['Du spærrer den ledige container FRI inde. Den kan tages nu.']);
    $board->assertSee('Du spærrer den ledige container FRI inde.');

    // Flytter man den ledige selv, er der intet at advare om.
    $free = App\Models\Container::query()->where('number', 'FRI')->first();
    expect(app(PlacementRules::class)->warnings(slot($this->b, 9, 1), $free->id))->not->toContain('Du spærrer den ledige container FRI inde. Den kan tages nu.');
});

it('makes the work plan avoid trapping an available container before it looks at the cost', function () {
    foreach (['Buffer 1', 'Buffer 2', 'Buffer 3', 'Buffer 4'] as $name) {
        foreach (range(1, 3) as $level) {
            rentedAt(row("Lager 1 · {$name}"), 1, $level);
        }
    }
    putAt($this->b, 10, 1, ['number' => 'FRI']);
    rentedAt($this->c, 10, 1);
    rentedAt($this->c, 10, 2);
    $target = rentedAt($this->a, 2, 1);
    $blocker = rentedAt($this->a, 1, 1);

    $planner = app(WorkPlanner::class);
    $step = $planner->plan($target)['steps'][0];

    // B spærrer kun for 1, men spærrer den ledige inde. C spærrer for 2 og vælges.
    expect($step['to']->rowId)->toBe($this->c->id)
        ->and($step['cost'])->toBe(2)
        ->and($step['traps'])->toBeNull()
        // C har 10 pladser, der ikke spærrer den ledige inde, så de fylder alle valgene.
        ->and(collect($step['options'])->pluck('traps')->unique()->all())->toBe([null]);

    // Vælger medarbejderen alligevel B, får trinnet mærkaten.
    $chosen = $planner->plan($target, [$blocker->id => slot($this->b, 10, 2)->key()])['steps'][0];
    expect($chosen['traps'])->toBe('FRI');
});

it('does not park a transfer on top of an available container in the buffer', function () {
    $lager2 = Warehouse::query()->where('name', 'Lager 2')->first();
    putAt(row('Lager 2 · Buffer 1'), 1, 1, ['number' => 'FRI']);
    rentedAt(row('Lager 2 · Buffer 2'), 1, 1);
    rentedAt(row('Lager 2 · Buffer 3'), 1, 1);
    rentedAt(row('Lager 2 · Buffer 4'), 1, 1);
    $moving = rentedAt($this->a, 1, 1);

    $plan = app(TransferPlanner::class)->plan($moving, $lager2);

    expect($plan['slot']->key())->toBe(slot(row('Lager 2 · Buffer 2'), 1, 2)->key());
});
