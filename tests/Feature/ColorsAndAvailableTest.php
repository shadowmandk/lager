<?php

use App\Livewire\Admin;
use App\Livewire\Board;
use App\Models\Container;
use App\Models\Customer;
use App\Models\Operator;
use App\Models\User;
use App\Services\StatisticsService;
use App\Support\CustomerPalette;
use Livewire\Livewire;

beforeEach(function () {
    seedLayout();
    $this->op = Operator::query()->create(['name' => 'Bente']);
    $this->a = row('Lager 1 · A');
});

it('gives new customers the least used colour', function () {
    $first = Customer::query()->create(['number' => '1', 'name' => 'Første']);
    $second = Customer::query()->create(['number' => '2', 'name' => 'Anden']);

    expect($first->color)->toBe(0)->and($second->color)->toBe(1);

    $first->update(['color' => 5]);
    $third = Customer::query()->create(['number' => '3', 'name' => 'Tredje']);

    expect($third->color)->toBe(0)
        ->and(Customer::query()->find($first->id)->color())->toBe(CustomerPalette::COLORS[5]);
});

it('has 24 distinct colours', function () {
    expect(CustomerPalette::COLORS)->toHaveCount(24)
        ->and(collect(CustomerPalette::COLORS)->pluck('side')->unique())->toHaveCount(24);
});

it('lets the admin choose a colour, also for Microbizz customers', function () {
    $local = Customer::query()->create(['number' => '1', 'name' => 'Lokal']);
    $mb = Customer::query()->create(['number' => '2', 'name' => 'Fra Microbizz', 'microbizz_id' => 99]);

    Livewire::actingAs(User::factory()->create())
        ->test(Admin::class, ['tab' => 'customers'])
        ->call('pickColor', $local->id)
        ->assertSet('colorCustomerId', $local->id)
        ->assertSee('Vælg farve til Lokal')
        ->call('setCustomerColor', $local->id, 17)
        ->assertSet('colorCustomerId', null)
        ->call('setCustomerColor', $mb->id, 3)
        ->call('setCustomerColor', $mb->id, 99);

    expect($local->fresh()->color)->toBe(17)->and($mb->fresh()->color)->toBe(3);
});

it('shows a stored container without customer as available', function () {
    $cust = Customer::query()->create(['number' => '1', 'name' => 'Hansen']);
    $free = putAt($this->a, 10, 1, ['number' => 'FRI1']);
    putAt($this->a, 10, 2, ['number' => 'KUN1', 'customer_id' => $cust->id]);
    $out = Container::query()->create(['number' => 'UD1', 'status' => Container::DELIVERED]);

    expect($free->isAvailable())->toBeTrue()
        ->and($free->customerName())->toBe('Ledig')
        ->and($free->color())->toBe(CustomerPalette::AVAILABLE)
        ->and($out->isAvailable())->toBeFalse()
        ->and($out->customerName())->toBe('Ingen kunde');

    expect(Container::query()->search('ledig')->pluck('number')->all())->toBe(['FRI1'])
        ->and(Container::query()->search('Hansen')->pluck('number')->all())->toBe(['KUN1']);

    session(['operator_id' => $this->op->id]);

    $board = Livewire::test(Board::class)
        ->assertViewHas('availableHere', 1)
        ->assertViewHas('availableTotal', 1)
        ->call('showAvailable')
        ->assertSet('search', 'ledig');

    expect($board->html())->toContain('>LEDIG</text>')->toContain('aria-label="Forklaring"');

    expect(app(StatisticsService::class)->capacity()['total']['available'])->toBe(1);
});
