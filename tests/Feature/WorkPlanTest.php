<?php

use App\Livewire\Board;
use App\Models\Activity;
use App\Models\Operator;
use App\Services\UndoService;
use App\Services\WorkPlanner;
use Livewire\Livewire;

beforeEach(function () {
    seedLayout();
    $this->op = Operator::query()->create(['name' => 'Bente']);
    $this->planner = app(WorkPlanner::class);
    $this->a = row('Lager 1 · A');
    $this->b = row('Lager 1 · B');
    $this->c = row('Lager 1 · C');
    $this->buffer1 = row('Lager 1 · Buffer 1');
});

/** @return list<string> pladsnøglerne i planen, i rækkefølge */
function planTargets(array $plan): array
{
    return array_map(fn ($s) => $s['to']?->key(), $plan['steps']);
}

it('puts a blocker at the back of an empty row rather than in the buffer', function () {
    $target = putAt($this->a, 2, 1);
    $blocker = putAt($this->a, 1, 1);

    $plan = $this->planner->plan($target);

    expect($plan['blockers'])->toBe(1)
        ->and($plan['problem'])->toBeNull()
        ->and($plan['steps'][0]['container']->id)->toBe($blocker->id)
        ->and($plan['steps'][0]['fromLabel'])->toBe('A 1 1')
        ->and($plan['steps'][0]['label'])->toBe('B 10 1')
        ->and($plan['steps'][0]['cost'])->toBe(0)
        ->and($plan['steps'][0]['buffer'])->toBeFalse();
});

it('keeps later blockers from burying the ones it already moved, and uses the buffer when rows would', function () {
    $target = putAt($this->a, 2, 1);
    putAt($this->a, 1, 1);
    putAt($this->a, 1, 2);
    putAt($this->a, 1, 3);

    $plan = $this->planner->plan($target);

    // Toppen først. Nummer 2 kommer ikke oven på nummer 1, og nummer 3 ville spærre i begge rækker.
    expect(planTargets($plan))->toBe([
        slot($this->b, 10, 1)->key(),
        slot($this->c, 10, 1)->key(),
        slot($this->buffer1, 1, 1)->key(),
    ])
        ->and($plan['buffer'])->toBe(1)
        ->and($plan['cost'])->toBe(0)
        ->and($plan['minutes'])->toBe(3 * App\Models\Setting::minutesPerMove());
});

it('counts the containers a placement would bury', function () {
    putAt($this->b, 10, 1);
    putAt($this->b, 10, 2);
    putAt($this->c, 10, 1);
    fillColumns($this->buffer1, [1]);
    foreach (['Buffer 2', 'Buffer 3', 'Buffer 4'] as $name) {
        fillColumns(row("Lager 1 · {$name}"), [1]);
    }
    $target = putAt($this->a, 2, 1);
    putAt($this->a, 1, 1);

    $step = $this->planner->plan($target)['steps'][0];

    // Alt i C spærrer for én og alt i B for to. C 10 2 spærrer heller ingen tomme pladser inde.
    expect($step['label'])->toBe('C 10 2')
        ->and($step['cost'])->toBe(1)
        ->and(collect($step['options'])->pluck('cost')->unique()->all())->toBe([1]);
});

it('replans the following steps around a chosen slot and drops a choice that is not free', function () {
    $target = putAt($this->a, 2, 1);
    $top = putAt($this->a, 1, 2);
    $bottom = putAt($this->a, 1, 1);

    $plan = $this->planner->plan($target, [$top->id => slot($this->c, 10, 1)->key()]);

    expect(planTargets($plan))->toBe([slot($this->c, 10, 1)->key(), slot($this->b, 10, 1)->key()])
        ->and($plan['steps'][0]['chosen'])->toBeTrue()
        ->and($plan['steps'][1]['chosen'])->toBeFalse()
        ->and($plan['dropped'])->toBe([]);

    // Bagerst i B er ikke ledig, når der står en foran; og egen række er aldrig et valg.
    putAt($this->b, 9, 1);
    $plan = $this->planner->plan($target, [$top->id => slot($this->b, 10, 1)->key(), $bottom->id => slot($this->a, 3, 1)->key()]);

    expect($plan['dropped'])->toBe([$top->id, $bottom->id])
        ->and($plan['steps'][0]['chosen'])->toBeFalse();
});

it('says how many slots are missing', function () {
    $l2a = row('Lager 2 · A');
    fillColumns(row('Lager 2 · B'), range(1, 6));
    fillColumns(row('Lager 2 · C'), range(2, 6));
    foreach (range(1, 4) as $i) {
        fillColumns(row("Lager 2 · Buffer {$i}"), [1]);
    }
    putAt(row('Lager 2 · C'), 1, 1);
    putAt(row('Lager 2 · C'), 1, 2);

    $target = putAt($l2a, 2, 1);
    putAt($l2a, 1, 1);
    putAt($l2a, 1, 2);

    // Kun C 1 3 er ledig, så den nederste container i vejen har ingen plads.
    $plan = $this->planner->plan($target);

    expect($plan['missing'])->toBe(1)
        ->and(count($plan['steps']))->toBe(1)
        ->and($plan['problem'])->toContain('mangler en ledig plads');
});

it('runs a plan step by step on the board, refusing a stale step and following undo', function () {
    session(['operator_id' => $this->op->id]);
    $target = putAt($this->a, 2, 1, ['number' => 'MÅL']);
    $bottom = putAt($this->a, 1, 1);
    $top = putAt($this->a, 1, 2);

    $board = Livewire::test(Board::class)
        ->call('openPlan', $target->id)
        ->assertSet('planOpen', true)
        ->assertSee('Kom ind til MÅL')
        ->call('startPlan')
        ->assertSet('planOpen', false)
        ->assertSee('trin 1 af 2');

    expect(session('workplan')['started'])->toBeTrue();

    // Et tryk på et trin, der ikke er det næste, flytter intet.
    $board->call('stepDone', $bottom->id, slot($this->a, 1, 1)->key(), slot($this->c, 10, 1)->key())
        ->assertSet('noticeType', 'error');
    expect($bottom->fresh()->depth)->toBe(1);

    $board->call('stepDone', $top->id, slot($this->a, 1, 2)->key(), slot($this->b, 10, 1)->key())
        ->assertSet('noticeType', 'ok')
        ->assertSee('trin 2 af 2');
    expect($top->fresh()->storage_row_id)->toBe($this->b->id);

    // Et dobbelttryk sender det samme trin igen og bliver afvist.
    $board->call('stepDone', $top->id, slot($this->a, 1, 2)->key(), slot($this->b, 10, 1)->key())
        ->assertSet('noticeType', 'error');

    // Fortrydes trinet, er containeren tilbage i planen.
    app(UndoService::class)->undo(Activity::query()->latest('id')->first(), actor());
    $board->call('$refresh')->assertSee('trin 1 af 2');

    $board->call('stepDone', $top->id, slot($this->a, 1, 2)->key(), slot($this->b, 10, 1)->key())
        ->call('stepDone', $bottom->id, slot($this->a, 1, 1)->key(), slot($this->c, 10, 1)->key())
        ->assertSet('panelId', $target->id)
        ->assertSet('notice', 'Vejen er fri. MÅL kan tages nu.');

    expect(session('workplan'))->toBeNull()
        ->and(Activity::query()->where('action', Activity::MOVED)->whereNull('undone_by_id')->count())->toBe(2);
});

it('will not start a plan that cannot be finished, and stops when the target leaves', function () {
    session(['operator_id' => $this->op->id]);
    $target = putAt($this->a, 2, 1);
    putAt($this->a, 1, 1);

    $board = Livewire::test(Board::class)->call('openPlan', $target->id)->call('closePlan');
    expect(session('workplan'))->toBeNull();

    $board->call('openPlan', $target->id)->call('startPlan');
    $target->update(['status' => App\Models\Container::DELIVERED, 'storage_row_id' => null, 'depth' => null, 'level' => null]);

    $board->call('$refresh')->assertSet('noticeType', 'error');
    expect(session('workplan'))->toBeNull();

    $free = putAt($this->b, 1, 1);
    $board->call('openPlan', $free->id)->assertSet('planOpen', false)->assertSet('notice', "{$free->number} kan tages nu. Der skal ikke flyttes noget først.");
});
