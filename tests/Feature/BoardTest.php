<?php

use App\Livewire\ActivityLog;
use App\Livewire\Admin;
use App\Livewire\AdminLogin;
use App\Livewire\Board;
use App\Models\Activity;
use App\Models\Container;
use App\Models\Operator;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    seedLayout();
    $this->op = Operator::query()->create(['name' => 'Bente']);
    $this->a = row('Lager 1 · A');
    $this->b = row('Lager 1 · B');
});

it('sends people without a chosen operator to the picker', function () {
    $this->get('/tavle')->assertRedirect(route('operator.pick'));
    $this->get('/log')->assertRedirect(route('operator.pick'));
});

it('keeps the admin pages behind a login', function () {
    $this->withSession(['operator_id' => $this->op->id])->get('/admin')->assertRedirect(route('login'));
});

it('renders the board with the isometric rows', function () {
    putAt($this->a, 10, 1, ['number' => '4711']);

    $this->withSession(['operator_id' => $this->op->id])
        ->get('/tavle')
        ->assertOk()
        ->assertSee('4711')
        ->assertSee('data-drag-kind="stored"', false);
});

it('answers the ping with a version that changes when the board changes', function () {
    $before = $this->getJson('/ping')->assertOk()->json('version');
    putAt($this->a, 10, 1);

    expect($this->getJson('/ping')->json('version'))->not->toBe($before);
});

it('moves a container through prepare and confirm, logged on the chosen operator', function () {
    $c = putAt($this->a, 10, 1);

    Livewire::withQueryParams([])
        ->test(Board::class)
        ->tap(fn () => session(['operator_id' => $this->op->id]))
        ->call('prepare', $c->id, slot($this->a, 10, 1)->key(), slot($this->b, 10, 1)->key())
        ->assertSet('confirm.type', 'move')
        ->assertSee('Lager 1 · B · dybde 10, højde 1')
        ->call('confirmMove')
        ->assertSet('confirm', null)
        ->assertSet('noticeType', 'ok');

    expect($c->fresh()->storage_row_id)->toBe($this->b->id)
        ->and(Activity::query()->latest('id')->first()->operator_id)->toBe($this->op->id);
});

it('refuses to prepare an illegal move', function () {
    $behind = putAt($this->a, 10, 1);
    putAt($this->a, 9, 1);

    session(['operator_id' => $this->op->id]);

    Livewire::test(Board::class)
        ->call('prepare', $behind->id, slot($this->a, 10, 1)->key(), slot($this->b, 10, 1)->key())
        ->assertSet('confirm', null)
        ->assertSet('noticeType', 'error')
        ->assertSet('notice', 'Containeren er spærret af 1 container.');
});

it('detects that the board changed between drag and drop', function () {
    $c = putAt($this->a, 10, 1);
    session(['operator_id' => $this->op->id]);

    Livewire::test(Board::class)
        ->call('prepare', $c->id, slot($this->a, 9, 1)->key(), slot($this->b, 10, 1)->key())
        ->assertSet('confirm', null)
        ->assertSet('noticeType', 'error');
});

it('returns a delivered container with a chosen customer', function () {
    $cust = App\Models\Customer::query()->create(['number' => '77', 'name' => 'Ny kunde']);
    $c = Container::query()->create(['number' => 'R1', 'status' => Container::DELIVERED]);
    session(['operator_id' => $this->op->id]);

    Livewire::test(Board::class)
        ->call('prepare', $c->id, null, slot($this->a, 10, 1)->key())
        ->assertSet('confirm.type', 'return')
        ->set('returnCustomerId', $cust->id)
        ->call('confirmMove');

    expect($c->fresh()->isStored())->toBeTrue()->and($c->fresh()->customer_id)->toBe($cust->id);
});

it('undoes from the log page', function () {
    $c = putAt($this->a, 10, 1);
    app(App\Services\MoveService::class)->move($c, slot($this->a, 10, 1), slot($this->b, 10, 1), App\Services\Actor::operator($this->op));
    session(['operator_id' => $this->op->id]);

    $log = Activity::query()->first();

    Livewire::test(ActivityLog::class)
        ->assertSee('Fortryd')
        ->call('askUndo', $log->id)
        ->call('undo')
        ->assertSet('noticeType', 'ok');

    expect($c->fresh()->storage_row_id)->toBe($this->a->id);
});

it('logs admins in and rejects wrong passwords', function () {
    User::query()->create(['name' => 'Admin', 'email' => 'a@lager.test', 'password' => 'en-lang-kode-123']);

    Livewire::test(AdminLogin::class)
        ->set('email', 'a@lager.test')->set('password', 'forkert')
        ->call('login')
        ->assertHasErrors('email');

    Livewire::test(AdminLogin::class)
        ->set('email', 'a@lager.test')->set('password', 'en-lang-kode-123')
        ->call('login')
        ->assertRedirect(route('admin'));
});

it('lets an admin create a container that waits for a slot', function () {
    $admin = User::query()->create(['name' => 'Admin', 'email' => 'a@lager.test', 'password' => 'en-lang-kode-123']);

    Livewire::actingAs($admin)
        ->test(Admin::class, ['tab' => 'containers'])
        ->set('containerForm.number', '7001')
        ->set('containerForm.name', 'Ny')
        ->call('createContainer')
        ->assertHasNoErrors();

    $c = Container::query()->where('number', '7001')->first();

    expect($c->status)->toBe(Container::PENDING)
        ->and(Activity::query()->where('action', Activity::CREATED)->first()->user_id)->toBe($admin->id);
});

it('shows a clear error when an admin shrinks a row with containers on it', function () {
    $admin = User::query()->create(['name' => 'Admin', 'email' => 'a@lager.test', 'password' => 'en-lang-kode-123']);
    putAt($this->a, 10, 1, ['number' => '9001']);

    Livewire::actingAs($admin)
        ->test(Admin::class)
        ->call('editRow', $this->a->id)
        ->set('rowEdit.depth', 8)
        ->call('saveRow')
        ->assertSet('noticeType', 'error');

    expect($this->a->fresh()->depth)->toBe(10);
});

it('explains why a stack without a green slot cannot be used', function () {
    $buffer = row('Lager 1 · Buffer 1');
    fillColumns($buffer, [1]);
    $c = putAt($this->a, 1, 1);

    session(['operator_id' => $this->op->id]);

    Livewire::test(Board::class)
        ->call('illegalDrop', $c->id, $buffer->id.':1')
        ->assertSet('noticeType', 'error')
        ->assertSet('notice', 'Stakken er fuld. Vælg en af de grønne pladser.');

    putAt($this->b, 1, 1);

    Livewire::test(Board::class)
        ->call('illegalDrop', $c->id, $this->b->id.':5')
        ->assertSet('notice', 'Der står containere foran pladsen. Vælg en af de grønne pladser.');

    Livewire::test(Board::class)
        ->call('illegalDrop')
        ->assertSet('notice', 'Det er ikke en lovlig plads. Kun de grønne pladser kan bruges.');
});
