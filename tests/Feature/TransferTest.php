<?php

use App\Livewire\Board;
use App\Models\Activity;
use App\Models\Container;
use App\Models\Operator;
use App\Models\Warehouse;
use App\Services\LocationLabel;
use App\Services\MoveRejected;
use App\Services\MoveService;
use App\Services\PlacementRules;
use App\Services\RowGrid;
use App\Services\TransferPlanner;
use App\Services\UndoService;
use Livewire\Livewire;

beforeEach(function () {
    seedLayout();
    $this->moves = app(MoveService::class);
    $this->planner = app(TransferPlanner::class);
    $this->a = row('Lager 1 · A');
    $this->lager2 = Warehouse::query()->where('name', 'Lager 2')->firstOrFail();
    $this->buf1 = row('Lager 2 · Buffer 1');
    $this->buf2 = row('Lager 2 · Buffer 2');
});

it('names slots by row, place from the entrance and height from the bottom', function () {
    expect(LocationLabel::place(slot($this->a, 3, 2)))->toBe('Lager 1 · A 3 2')
        ->and(LocationLabel::place(slot(row('Lager 1 · Buffer 2'), 1, 1)))->toBe('Lager 1 · Buffer 2 1')
        ->and(LocationLabel::for(Container::TRANSIT, slot($this->buf1, 1, 1)))->toBe('I transit til Lager 2 · Buffer 1 1');
});

it('reserves the lowest free buffer slot on the other warehouse', function () {
    $c = putAt($this->a, 1, 1);
    putAt($this->buf1, 1, 1);
    putAt($this->buf1, 1, 2);

    $plan = $this->planner->plan($c, $this->lager2);

    expect($plan['slot']->key())->toBe(slot($this->buf2, 1, 1)->key())
        ->and($plan['label'])->toBe('Lager 2 · Buffer 2 1')
        ->and($plan['reason'])->toBeNull();
});

it('refuses when the buffer is full, missing, or the container is blocked', function () {
    $c = putAt($this->a, 2, 1);
    putAt($this->a, 1, 1);

    expect($this->planner->plan($c, $this->lager2)['reason'])->toBe('Containeren er spærret af 1 container.');

    $free = putAt(row('Lager 1 · B'), 1, 1);

    foreach (range(1, 4) as $i) {
        fillColumns(row("Lager 2 · Buffer {$i}"), [1]);
    }

    expect($this->planner->plan($free, $this->lager2)['reason'])->toBe('Bufferen på Lager 2 er fuld.');

    $lager3 = Warehouse::query()->create(['name' => 'Lager 3', 'sort' => 3]);

    expect($this->planner->plan($free, $lager3)['reason'])->toBe('Lager 3 har ingen buffer. Den oprettes under opsætning.');
});

it('sends a container into transit, freeing its old slot and reserving the buffer slot', function () {
    $c = putAt($this->a, 1, 1);

    $log = $this->moves->transfer($c, slot($this->a, 1, 1), slot($this->buf1, 1, 1), actor());
    $c->refresh();

    expect($c->status)->toBe(Container::TRANSIT)
        ->and($c->location()->key())->toBe(slot($this->buf1, 1, 1)->key())
        ->and($c->slot())->toBeNull()
        ->and($log->action)->toBe(Activity::TRANSFERRED)
        ->and($log->from_label)->toBe('Lager 1 · A 1 1')
        ->and($log->to_label)->toBe('I transit til Lager 2 · Buffer 1 1')
        ->and(RowGrid::load($this->a)->count())->toBe(0)
        ->and(app(PlacementRules::class)->canPlace(slot($this->buf1, 1, 1)))->toBeFalse();
});

it('locks a container in transit: it cannot be taken and nothing can go on top of it', function () {
    $c = putAt($this->a, 1, 1);
    $this->moves->transfer($c, slot($this->a, 1, 1), slot($this->buf1, 1, 1), actor());
    $c->refresh();

    $grid = RowGrid::load($this->buf1);

    expect($grid->takeable())->toBeNull()
        ->and($grid->legalTargets())->toBe([])
        ->and($grid->whyCannotPlace(1, 2))->toBe('Containeren nedenunder er i transit og er ikke meldt sat i buffer endnu.')
        ->and(app(PlacementRules::class)->whyCannotTake($c))->toStartWith('Containeren er i transit.');

    expect(fn () => $this->moves->deliver($c, slot($this->buf1, 1, 1), actor()))->toThrow(MoveRejected::class);
});

it('only sends containers to a buffer on another warehouse', function () {
    $c = putAt($this->a, 1, 1);

    expect(fn () => $this->moves->transfer($c, slot($this->a, 1, 1), slot(row('Lager 1 · Buffer 1'), 1, 1), actor()))
        ->toThrow(MoveRejected::class, 'Containeren kan kun sendes til bufferen på et andet lager.');

    expect(fn () => $this->moves->transfer($c, slot($this->a, 1, 1), slot(row('Lager 2 · A'), 1, 1), actor()))
        ->toThrow(MoveRejected::class, 'Containeren kan kun sendes til bufferen på et andet lager.');
});

it('stores the container on its reserved slot when it arrives', function () {
    $c = putAt($this->a, 1, 1);
    $this->moves->transfer($c, slot($this->a, 1, 1), slot($this->buf1, 1, 1), actor());

    $log = $this->moves->arrive($c, slot($this->buf1, 1, 1), actor());
    $c->refresh();

    expect($c->status)->toBe(Container::STORED)
        ->and($c->slot()->key())->toBe(slot($this->buf1, 1, 1)->key())
        ->and($log->action)->toBe(Activity::ARRIVED)
        ->and($log->from_label)->toBe('I transit til Lager 2 · Buffer 1 1')
        ->and($log->to_label)->toBe('Lager 2 · Buffer 1 1')
        ->and(RowGrid::load($this->buf1)->takeable()?->id)->toBe($c->id);
});

it('undoes arrival and transfer in order', function () {
    $undo = app(UndoService::class);
    $c = putAt($this->a, 1, 1);
    $sent = $this->moves->transfer($c, slot($this->a, 1, 1), slot($this->buf1, 1, 1), actor());
    $arrived = $this->moves->arrive($c, slot($this->buf1, 1, 1), actor());

    $undo->undo($arrived, actor());
    expect($c->fresh()->status)->toBe(Container::TRANSIT);

    $undo->undo($sent, actor());
    $c->refresh();

    expect($c->status)->toBe(Container::STORED)
        ->and($c->slot()->key())->toBe(slot($this->a, 1, 1)->key())
        ->and(RowGrid::load($this->buf1)->count())->toBe(0);
});

it('runs a transfer from the panel through confirmation and arrival', function () {
    $op = Operator::query()->create(['name' => 'Bente']);
    session(['operator_id' => $op->id]);
    $c = putAt($this->a, 1, 1, ['number' => '4711']);

    Livewire::test(Board::class)
        ->call('openPanel', $c->id)
        ->assertSee('Flyt til Lager 2')
        ->assertSee('Får bufferplads Lager 2 · Buffer 1 1.')
        ->call('prepareTransfer', $c->id, slot($this->a, 1, 1)->key(), $this->lager2->id)
        ->assertSet('confirm.type', 'transfer')
        ->assertSet('confirm.toLabel', 'Lager 2 · Buffer 1 1')
        ->call('confirmMove')
        ->assertSet('noticeType', 'ok')
        ->assertSee('I transit (1)')
        ->call('pick', $c->id)
        ->assertSet('noticeType', 'error')
        ->assertSet('picked', null)
        ->call('prepareArrive', $c->id)
        ->assertSet('confirm.type', 'arrive')
        ->call('confirmMove')
        ->assertSet('notice', 'Container 4711 står nu på Lager 2 · Buffer 1 1.');

    expect($c->fresh()->status)->toBe(Container::STORED)
        ->and(Activity::query()->where('container_id', $c->id)->pluck('operator_id')->unique()->all())->toBe([$op->id]);
});
