<?php

use App\Livewire\Admin;
use App\Livewire\Board;
use App\Livewire\Statistics;
use App\Models\Activity;
use App\Models\Container;
use App\Models\Operator;
use App\Models\User;
use App\Services\MoveRejected;
use App\Services\MoveService;
use App\Services\StatisticsService;
use App\Support\NumberSeries;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

beforeEach(function () {
    seedLayout();
    $this->op = Operator::query()->create(['name' => 'Bente']);
    $this->moves = app(MoveService::class);
    $this->a = row('Lager 1 · A');
});

afterEach(fn () => CarbonImmutable::setTestNow());

it('creates a container from the board and selects it for placing', function () {
    session(['operator_id' => $this->op->id]);

    $component = Livewire::test(Board::class)
        ->call('openCreate')
        ->assertSet('creating', true)
        ->set('newContainer.number', ' msku 1234 ')
        ->call('createContainer')
        ->assertHasNoErrors()
        ->assertSet('creating', false);

    $c = Container::query()->where('number', 'MSKU 1234')->firstOrFail();

    $component->assertSet('picked', $c->id);

    expect($c->status)->toBe(Container::PENDING)
        ->and(Activity::query()->where('container_id', $c->id)->value('operator_id'))->toBe($this->op->id);

    Livewire::test(Board::class)
        ->call('openCreate')
        ->set('newContainer.number', 'MSKU 1234')
        ->call('createContainer')
        ->assertHasErrors('newContainer.number');
});

it('expands number series and keeps leading zeros', function () {
    expect(NumberSeries::expand('4101', '4103'))->toBe(['4101', '4102', '4103'])
        ->and(NumberSeries::expand('msku008', 'MSKU010'))->toBe(['MSKU008', 'MSKU009', 'MSKU010']);

    expect(fn () => NumberSeries::expand('A1', 'B3'))->toThrow(MoveRejected::class, 'Fra og til skal starte ens');
    expect(fn () => NumberSeries::expand('10', '5'))->toThrow(MoveRejected::class);
    expect(fn () => NumberSeries::expand('1', '500'))->toThrow(MoveRejected::class, 'højst oprettes 200');
});

it('creates a series from the admin page and skips numbers that exist', function () {
    $this->moves->create('4102', null, null, Container::PENDING, actor());
    $this->actingAs(User::factory()->create());

    Livewire::test(Admin::class)
        ->set('tab', 'containers')
        ->set('containerForm.number', '4101')
        ->set('containerForm.to', '4104')
        ->set('containerForm.name', 'Udstyr')
        ->call('createContainer')
        ->assertHasNoErrors()
        ->assertSet('notice', '3 containere er oprettet, fra 4101 til 4104. De står under “Venter på plads” på tavlen. Disse numre fandtes allerede og er sprunget over: 4102.');

    expect(Container::query()->where('name', 'Udstyr')->pluck('number')->all())->toBe(['4101', '4103', '4104']);
});

it('counts capacity, including slots reserved for containers in transit', function () {
    putAt($this->a, 10, 1);
    putAt($this->a, 10, 2);
    $c = putAt(row('Lager 1 · B'), 1, 1);
    $this->moves->transfer($c, slot(row('Lager 1 · B'), 1, 1), slot(row('Lager 2 · Buffer 1'), 1, 1), actor());

    $cap = app(StatisticsService::class)->capacity();
    [$l1, $l2] = $cap['warehouses'];

    expect($l1)->toMatchArray(['name' => 'Lager 1', 'rows_capacity' => 90, 'rows_used' => 2, 'buffer_capacity' => 12, 'used' => 2, 'free' => 100])
        ->and($l2)->toMatchArray(['name' => 'Lager 2', 'buffer_used' => 1, 'transit' => 1, 'used' => 1])
        ->and($cap['transit'])->toBe(1);
});

it('counts moves, in and out, leaving out undone actions', function () {
    CarbonImmutable::setTestNow('2026-10-28 12:00');
    $c = putAt($this->a, 1, 1);
    $this->moves->move($c, slot($this->a, 1, 1), slot($this->a, 2, 1), actor());
    $this->moves->deliver($c->fresh(), slot($this->a, 2, 1), actor());
    $this->moves->returnHome($c->fresh(), slot($this->a, 1, 1), null, actor());
    $undone = $this->moves->move($c->fresh(), slot($this->a, 1, 1), slot($this->a, 2, 1), actor());
    app(App\Services\UndoService::class)->undo($undone, actor());

    $s = app(StatisticsService::class)->summary(7);

    expect($s)->toMatchArray(['moves' => 3, 'in' => 1, 'out' => 1])
        ->and($s['byOperator'])->toBe(['Test' => 3]);
});

it('forecasts when a warehouse fills up from the net inflow of recent weeks', function () {
    CarbonImmutable::setTestNow('2026-10-28 12:00');
    $rowB = row('Lager 1 · B');
    $c = putAt($this->a, 10, 1);

    // 4 uger med 3 containere ind pr. uge til Lager 1 og ingen ud.
    foreach (range(1, 4) as $week) {
        foreach (range(1, 3) as $i) {
            Activity::query()->insert([
                'action' => Activity::RETURNED, 'operator_id' => $this->op->id, 'container_id' => $c->id,
                'to_status' => Container::STORED, 'to_row_id' => $rowB->id, 'to_depth' => 1, 'to_level' => 1,
                'created_at' => CarbonImmutable::now()->startOfWeek()->subWeeks($week)->addDays($i)->toDateTimeString(),
            ]);
        }
    }

    $f = app(StatisticsService::class)->forecast();
    $l1 = collect($f['warehouses'])->firstWhere('name', 'Lager 1');
    $l2 = collect($f['warehouses'])->firstWhere('name', 'Lager 2');

    expect($f['enough'])->toBeTrue()
        ->and($f['avgIn'])->toEqual(3.0)
        ->and($f['avgOut'])->toEqual(0.0)
        ->and($l1['netPerWeek'])->toEqual(3.0)
        ->and($l1['weeksToFull'])->toEqual(round(101 / 3, 1))
        ->and($l2['weeksToFull'])->toBeNull();
});

it('says so when there is too little history for a forecast', function () {
    expect(app(StatisticsService::class)->forecast()['enough'])->toBeFalse();
});

it('renders the statistics page for a chosen operator', function () {
    $this->withSession(['operator_id' => $this->op->id])
        ->get('/statistik')
        ->assertOk()
        ->assertSee('Kapacitet lige nu')
        ->assertSee('Forecast kræver mindst 2 ugers historik');

    session(['operator_id' => $this->op->id]);
    Livewire::test(Statistics::class)->call('setDays', 7)->assertSet('days', 7)->call('setDays', 999)->assertSet('days', 30);
});
