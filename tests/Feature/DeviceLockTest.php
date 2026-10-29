<?php

use App\Livewire\Admin;
use App\Livewire\UnlockScreen;
use App\Models\Operator;
use App\Models\User;
use App\Support\DeviceLock;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

beforeEach(function () {
    seedLayout();
    $this->op = Operator::query()->create(['name' => 'Bente']);
    DeviceLock::setCode('246810');
});

it('sends a locked device to the lock screen', function () {
    $this->get('/')->assertRedirect(route('unlock'));
    $this->get('/tavle')->assertRedirect(route('unlock'));
    $this->withSession(['operator_id' => $this->op->id])->get('/statistik')->assertRedirect(route('unlock'));
    $this->getJson('/ping')->assertStatus(423)->assertJson(['locked' => true]);

    $this->get('/laas')->assertOk()->assertSee('Lås op');
});

it('unlocks with the right code and then asks who is driving', function () {
    Livewire::test(UnlockScreen::class)
        ->call('unlock', '111111')
        ->assertSet('error', 'Forkert kode. Prøv igen.')
        ->call('unlock', '246810')
        ->assertRedirect(route('operator.pick'));

    $this->get('/')->assertOk()->assertSee('Hvem kører?');
});

it('makes people wait after five wrong codes, and longer after ten', function () {
    $screen = Livewire::test(UnlockScreen::class);

    foreach (range(1, 4) as $i) {
        $screen->call('unlock', '000000')->assertSet('wait', 0);
    }

    $screen->call('unlock', '000000')->assertSet('wait', 60);
    $screen->call('unlock', '246810')->assertSet('wait', 60)->assertNoRedirect();

    $this->travel(61)->seconds();
    foreach (range(1, 5) as $i) {
        $screen->call('unlock', '000000');
    }
    $screen->assertSet('wait', 120);

    Cache::flush();
    $screen->call('unlock', '246810')->assertRedirect(route('operator.pick'));
});

it('locks again after 30 minutes without activity, but the ping does not count', function () {
    $this->withSession(unlocked(['operator_id' => $this->op->id]))->get('/tavle')->assertOk();

    $this->travel(20)->minutes();
    $this->getJson('/ping')->assertOk();

    $this->travel(11)->minutes();
    $this->getJson('/ping')->assertStatus(423);
    $this->get('/tavle')->assertRedirect(route('unlock'));
});

it('locks with the lock button and forgets the operator', function () {
    $this->withSession(unlocked(['operator_id' => $this->op->id]))->get('/tavle')->assertOk();

    $this->post('/laas')->assertRedirect(route('unlock'));

    $this->get('/tavle')->assertRedirect(route('unlock'));
    expect(session('operator_id'))->toBeNull();
});

it('locks every device when the admin changes the code', function () {
    $this->withSession(unlocked(['operator_id' => $this->op->id]))->get('/tavle')->assertOk();

    Livewire::actingAs(User::factory()->create())
        ->test(Admin::class, ['tab' => 'settings'])
        ->set('accessCode', '12345')->set('accessCodeRepeat', '12345')
        ->call('saveAccessCode')->assertHasErrors('accessCode')
        ->set('accessCode', '135790')->set('accessCodeRepeat', '135791')
        ->call('saveAccessCode')->assertHasErrors('accessCodeRepeat')
        ->set('accessCodeRepeat', '135790')
        ->call('saveAccessCode')->assertHasNoErrors()->assertSet('accessCode', '');

    $this->get('/tavle')->assertRedirect(route('unlock'));

    Livewire::test(UnlockScreen::class)->call('unlock', '246810')->assertNoRedirect();
    Livewire::test(UnlockScreen::class)->call('unlock', '135790')->assertRedirect(route('operator.pick'));
});

it('stays locked until a code has been chosen', function () {
    App\Models\Setting::put('access.code_hash', null);

    Livewire::test(UnlockScreen::class)
        ->assertSee('Der er ikke valgt nogen kode endnu')
        ->call('unlock', '246810')
        ->assertNoRedirect();
});

it('sets the code from ACCESS_CODE once, and from the command', function () {
    App\Models\Setting::put('access.code_hash', null);
    putenv('ACCESS_CODE=975310');
    (new Database\Seeders\AccessCodeSeeder)->run();
    putenv('ACCESS_CODE');

    Livewire::test(UnlockScreen::class)->call('unlock', '975310')->assertRedirect(route('operator.pick'));

    $this->artisan('lager:kode')
        ->expectsQuestion('Ny kode til låseskærmen (6 cifre)', '864200')
        ->expectsQuestion('Gentag koden', '864200')
        ->assertSuccessful();

    Livewire::test(UnlockScreen::class)->call('unlock', '864200')->assertRedirect(route('operator.pick'));
});
