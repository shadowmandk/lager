<?php

use App\Models\Activity;
use App\Models\Container;
use App\Models\Customer;
use App\Services\MoveRejected;
use App\Services\MoveService;
use App\Services\UndoService;

beforeEach(function () {
    seedLayout();
    $this->moves = app(MoveService::class);
    $this->undo = app(UndoService::class);
    $this->a = row('Lager 1 · A');
    $this->b = row('Lager 1 · B');
});

it('puts a moved container back where it came from', function () {
    $c = putAt($this->a, 10, 1);
    $log = $this->moves->move($c, slot($this->a, 10, 1), slot($this->b, 10, 1), actor());

    $undo = $this->undo->undo($log, actor());

    expect($c->fresh()->slot()->key())->toBe(slot($this->a, 10, 1)->key())
        ->and($undo->action)->toBe(Activity::UNDONE)
        ->and($undo->undoes_id)->toBe($log->id)
        ->and($log->fresh()->undone_by_id)->toBe($undo->id)
        ->and(Activity::query()->count())->toBe(2);
});

it('only undoes the newest action first, as a stack', function () {
    $c = putAt($this->a, 10, 1);
    $first = $this->moves->move($c, slot($this->a, 10, 1), slot($this->b, 10, 1), actor());
    $second = $this->moves->move($c->fresh(), slot($this->b, 10, 1), slot($this->a, 9, 1), actor());

    expect($this->undo->canUndo($first))->toBeFalse()
        ->and($this->undo->canUndo($second))->toBeTrue();

    $this->undo->undo($second, actor());

    expect($this->undo->canUndo($first->fresh()))->toBeTrue();

    $this->undo->undo($first->fresh(), actor());

    expect($c->fresh()->slot()->key())->toBe(slot($this->a, 10, 1)->key());
});

it('refuses to undo out of order', function () {
    $c = putAt($this->a, 10, 1);
    $first = $this->moves->move($c, slot($this->a, 10, 1), slot($this->b, 10, 1), actor());
    $this->moves->move($c->fresh(), slot($this->b, 10, 1), slot($this->a, 10, 1), actor());

    $this->undo->undo($first, actor());
})->throws(MoveRejected::class, 'Fortryd først de nyere handlinger');

it('limits undo to the last 15 actions', function () {
    $c = putAt($this->a, 10, 1);
    $here = slot($this->a, 10, 1);
    $there = slot($this->b, 10, 1);

    $logs = [];
    foreach (range(1, 16) as $i) {
        $logs[] = $this->moves->move($c->fresh(), $here, $there, actor());
        [$here, $there] = [$there, $here];
    }

    foreach (array_reverse(array_slice($logs, 1)) as $log) {
        $this->undo->undo($log->fresh(), actor());
    }

    expect($this->undo->candidate())->toBeNull()
        ->and($this->undo->canUndo($logs[0]->fresh()))->toBeFalse();
});

it('refuses to undo when the original slot has been taken', function () {
    $c = putAt($this->a, 10, 1);
    $log = $this->moves->move($c, slot($this->a, 10, 1), slot($this->b, 10, 1), actor());

    // En ny container sættes direkte på den gamle plads uden for systemets handlinger.
    putAt($this->a, 10, 1);

    expect(fn () => $this->undo->undo($log, actor()))->toThrow(MoveRejected::class, 'Pladsen er optaget.');
    expect($c->fresh()->storage_row_id)->toBe($this->b->id)
        ->and($log->fresh()->undone_by_id)->toBeNull();
});

it('brings a delivered container back to its slot', function () {
    $c = putAt($this->a, 10, 1);
    $log = $this->moves->deliver($c, slot($this->a, 10, 1), actor());

    $this->undo->undo($log, actor());

    expect($c->fresh()->isStored())->toBeTrue()->and($c->fresh()->depth)->toBe(10);
});

it('sends a returned container back out with the old customer', function () {
    $old = Customer::query()->create(['number' => '1', 'name' => 'Gammel']);
    $new = Customer::query()->create(['number' => '2', 'name' => 'Ny']);
    $c = Container::query()->create(['number' => 'X1', 'status' => Container::DELIVERED, 'customer_id' => $old->id]);
    $log = $this->moves->returnHome($c, slot($this->a, 10, 1), $new->id, actor());

    $this->undo->undo($log, actor());

    expect($c->fresh()->status)->toBe(Container::DELIVERED)
        ->and($c->fresh()->customer_id)->toBe($old->id);
});

it('takes a placed container back off the board', function () {
    $c = $this->moves->create('N1', null, null, Container::PENDING, actor());
    $log = $this->moves->place($c, slot($this->a, 10, 1), actor());

    $this->undo->undo($log, actor());

    expect($c->fresh()->status)->toBe(Container::PENDING);
});

it('restores name and customer on an undone edit', function () {
    $c = putAt($this->a, 10, 1, ['name' => 'Før']);
    $log = $this->moves->edit($c, 'Efter', null, actor());

    $this->undo->undo($log, actor());

    expect($c->fresh()->name)->toBe('Før');
});

it('does not undo creation', function () {
    $c = $this->moves->create('N1', null, null, Container::PENDING, actor());

    $this->undo->undo(Activity::query()->first(), actor());
})->throws(MoveRejected::class, 'Denne handling kan ikke fortrydes.');
