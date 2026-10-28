<?php

use App\Models\Activity;
use App\Models\Container;
use App\Models\Customer;
use App\Services\MoveRejected;
use App\Services\MoveService;

beforeEach(function () {
    seedLayout();
    $this->moves = app(MoveService::class);
    $this->a = row('Lager 1 · A');
    $this->b = row('Lager 1 · B');
});

it('moves a takeable container to a legal slot and logs it', function () {
    $c = putAt($this->a, 10, 1);

    $log = $this->moves->move($c, slot($this->a, 10, 1), slot($this->b, 10, 1), actor());

    expect($c->fresh()->slot()->key())->toBe(slot($this->b, 10, 1)->key())
        ->and($log->action)->toBe(Activity::MOVED)
        ->and($log->from_label)->toBe('Lager 1 · A · dybde 10, højde 1')
        ->and($log->to_label)->toBe('Lager 1 · B · dybde 10, højde 1')
        ->and($log->operator_id)->not->toBeNull();
});

it('can move a container within its own row onto the column behind it', function () {
    putAt($this->a, 10, 1);
    $c = putAt($this->a, 9, 1);

    $this->moves->move($c, slot($this->a, 9, 1), slot($this->a, 10, 2), actor());

    expect($c->fresh()->depth)->toBe(10)->and($c->fresh()->level)->toBe(2);
});

it('refuses to take a blocked container', function () {
    $behind = putAt($this->a, 10, 1);
    putAt($this->a, 9, 1);

    $this->moves->move($behind, slot($this->a, 10, 1), slot($this->b, 10, 1), actor());
})->throws(MoveRejected::class, 'Containeren er spærret af 1 container.');

it('refuses an illegal target', function () {
    $c = putAt($this->a, 10, 1);

    $this->moves->move($c, slot($this->a, 10, 1), slot($this->b, 10, 2), actor());
})->throws(MoveRejected::class, 'Der står ingen container under pladsen.');

it('rejects a move when the board changed in the meantime', function () {
    $c = putAt($this->a, 10, 1);
    $stale = $c->replicate();
    $stale->id = $c->id;

    // En anden bruger flytter containeren først.
    $this->moves->move($c, slot($this->a, 10, 1), slot($this->b, 10, 1), actor());

    // Den første bruger tror stadig, den står i række A.
    $this->moves->move($stale, slot($this->a, 10, 1), slot($this->a, 9, 1), actor());
})->throws(MoveRejected::class, 'Tavlen er ændret');

it('delivers a container to a customer and frees the slot', function () {
    $c = putAt($this->a, 10, 1);

    $log = $this->moves->deliver($c, slot($this->a, 10, 1), actor());

    expect($c->fresh()->status)->toBe(Container::DELIVERED)
        ->and($c->fresh()->storage_row_id)->toBeNull()
        ->and($log->to_label)->toBe('Udleveret til kunde');
});

it('refuses to deliver a blocked container', function () {
    $c = putAt($this->a, 10, 1);
    putAt($this->a, 10, 2);

    $this->moves->deliver($c, slot($this->a, 10, 1), actor());
})->throws(MoveRejected::class);

it('returns a delivered container with a new customer', function () {
    $old = Customer::query()->create(['number' => '1', 'name' => 'Gammel']);
    $new = Customer::query()->create(['number' => '2', 'name' => 'Ny']);
    $c = Container::query()->create(['number' => 'X1', 'status' => Container::DELIVERED, 'customer_id' => $old->id]);

    $log = $this->moves->returnHome($c, slot($this->a, 10, 1), $new->id, actor());

    expect($c->fresh()->status)->toBe(Container::STORED)
        ->and($c->fresh()->customer_id)->toBe($new->id)
        ->and($log->from_customer_id)->toBe($old->id)
        ->and($log->to_customer_id)->toBe($new->id);
});

it('places a new container', function () {
    $c = $this->moves->create('N1', 'Ny', null, Container::PENDING, actor());

    $this->moves->place($c, slot($this->a, 10, 1), actor());

    expect($c->fresh()->isStored())->toBeTrue()
        ->and(Activity::query()->pluck('action')->all())->toBe([Activity::CREATED, Activity::PLACED]);
});

it('refuses duplicate container numbers', function () {
    $this->moves->create('N1', null, null, Container::PENDING, actor());
    $this->moves->create('N1', null, null, Container::PENDING, actor());
})->throws(MoveRejected::class, 'Der findes allerede en container med nummer N1.');

it('edits name and customer and logs both sides', function () {
    $cust = Customer::query()->create(['number' => '9', 'name' => 'K']);
    $c = putAt($this->a, 10, 1, ['name' => 'Før']);

    $log = $this->moves->edit($c, 'Efter', $cust->id, actor());

    expect($log->from_name)->toBe('Før')->and($log->to_name)->toBe('Efter')
        ->and($log->to_customer_id)->toBe($cust->id)
        ->and($this->moves->edit($c->fresh(), 'Efter', $cust->id, actor()))->toBeNull();
});
