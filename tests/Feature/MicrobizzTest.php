<?php

use App\Livewire\Admin;
use App\Livewire\Board;
use App\Models\Activity;
use App\Models\Container;
use App\Models\Customer;
use App\Models\MicrobizzSync;
use App\Models\Operator;
use App\Models\Setting;
use App\Models\User;
use App\Services\Microbizz\MicrobizzException;
use App\Services\Microbizz\MicrobizzImporter;
use App\Services\Microbizz\MicrobizzSettings;
use App\Services\MoveRejected;
use App\Services\MoveService;
use App\Services\UndoService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/**
 * En lille falsk Microbizz. Tilstanden kan ændres mellem hentninger, og alle kommandoer gemmes i "log".
 * Udstyr sendes i sider af "page" stk. med resumekey, som det rigtige API gør ved store svar.
 */
function fakeMicrobizz(): ArrayObject
{
    $mb = new ArrayObject(['customers' => [], 'tools' => [], 'log' => [], 'page' => 100, 'fail' => false]);

    Http::fake(function (Request $request) use ($mb) {
        if ($mb['fail']) {
            return Http::response(['status' => 0, 'msg' => 'Invalid API key']);
        }

        $envelope = json_decode($request->data()['json'], true);
        $results = [];

        foreach ($envelope['commands'] as $cmd) {
            $mb['log'] = [...$mb['log'], $cmd];

            $results[] = match ($cmd['command']) {
                'GetCustomersByLastChange' => ['status' => 1, 'customers' => ($mb['unchanged'] ?? false) ? [] : $mb['customers']],
                'GetCustomerByID' => ['status' => 1, 'customers' => array_values(array_filter($mb['customers'], fn ($c) => $c['id'] === $cmd['customerid']))],
                'GetToolsByLastChange' => (function () use ($mb, $cmd) {
                    $offset = (int) ($cmd['resumekey'] ?? 0);
                    $more = $offset + $mb['page'] < count($mb['tools']);

                    return ['status' => 1, 'tools' => array_slice($mb['tools'], $offset, $mb['page'])]
                        + ($more ? ['resumekey' => (string) ($offset + $mb['page'])] : []);
                })(),
            };
        }

        return Http::response(['status' => 1, 'msg' => 'OK', 'results' => $results, 'date' => '2026-10-29', 'time' => '10:00:00']);
    });

    return $mb;
}

function tool(int $id, string $number, array $extra = []): array
{
    return $extra + ['id' => $id, 'number' => $number, 'name' => "Container {$number}", 'group' => 'Container', 'isdeleted' => 0, 'attachedcustomerid' => 0];
}

beforeEach(function () {
    seedLayout();
    config(['services.microbizz' => [
        'endpoint' => 'https://microbizz.test/api/endpoint.php',
        'contract' => '1234', 'apikey' => 'test-key', 'username' => 'api@test', 'password' => 'test-password', 'timeout' => 5,
    ]]);
    Setting::put(MicrobizzSettings::FILTER_FIELD, 'group');
    Setting::put(MicrobizzSettings::FILTER_VALUE, 'container');

    $this->mb = fakeMicrobizz();
    $this->import = fn () => app(MicrobizzImporter::class)->run('admin');
});

it('imports customers and containers, page by page, as waiting for a slot', function () {
    $this->mb['page'] = 2;
    $this->mb['customers'] = [['id' => 10, 'customernumber' => 'K100', 'name' => 'Hansen ApS', 'isdeleted' => 0]];
    $this->mb['tools'] = [
        tool(1, 'msku 1001', ['attachedcustomerid' => 10]),
        tool(2, 'MSKU1002'),
        tool(3, 'MSKU1003'),
        tool(4, 'VAN-7', ['group' => 'Biler']),
        tool(5, ''),
    ];

    $run = ($this->import)();

    expect($run->isOk())->toBeTrue()
        ->and($run->containers_created)->toBe(3)
        ->and($run->customers_created)->toBe(1)
        ->and($run->notes)->toHaveCount(1)
        ->and($run->notes[0])->toContain('intet containernummer');

    $c = Container::query()->where('number', 'MSKU 1001')->firstOrFail();

    expect($c->status)->toBe(Container::PENDING)
        ->and($c->microbizz_id)->toBe(1)
        ->and($c->customer->name)->toBe('Hansen ApS')
        ->and(Container::query()->where('number', 'VAN-7')->exists())->toBeFalse()
        ->and(Activity::query()->where('container_id', $c->id)->first()->actorName())->toBe('Microbizz');

    // Tre sider udstyr: 0-1, 2-3 og 4.
    expect(collect($this->mb['log'])->where('command', 'GetToolsByLastChange')->pluck('resumekey')->all())->toBe([null, '2', '4']);

    $envelope = json_decode(Http::recorded()[0][0]->data()['json'], true);
    expect($envelope)->toMatchArray(['contract' => '1234', 'apikey' => 'test-key', 'remoteagent' => 'Lagerstyring']);
});

it('only asks for changes since last time, with a small overlap', function () {
    ($this->import)();
    $this->mb['log'] = [];
    ($this->import)();

    expect(collect($this->mb['log'])->firstWhere('command', 'GetToolsByLastChange'))
        ->toMatchArray(['changedate' => '2026-10-29', 'changetime' => '09:55:00']);
});

it('links containers and customers that already exist here, and Microbizz decides number, name and customer', function () {
    $local = Customer::query()->create(['number' => 'K100', 'name' => 'Gammelt navn']);
    $other = Customer::query()->create(['number' => 'K200', 'name' => 'Anden kunde']);
    $stored = putAt(row('Lager 1 · A'), 10, 1, ['number' => 'MSKU 1001', 'customer_id' => $other->id]);

    $this->mb['customers'] = [['id' => 10, 'customernumber' => 'K100', 'name' => 'Hansen ApS', 'isdeleted' => 0]];
    $this->mb['tools'] = [tool(1, 'MSKU 1001', ['attachedcustomerid' => 10, 'name' => 'Lagerrum'])];

    $run = ($this->import)();
    $stored->refresh();

    expect($run->containers_updated)->toBe(1)
        ->and($run->customers_created)->toBe(0)
        ->and($local->fresh()->microbizz_id)->toBe(10)
        ->and($local->fresh()->name)->toBe('Hansen ApS')
        ->and($stored->microbizz_id)->toBe(1)
        ->and($stored->customer_id)->toBe($local->id)
        ->and($stored->name)->toBe('Lagerrum')
        ->and($stored->status)->toBe(Container::STORED)
        ->and($stored->slot()->key())->toBe(slot(row('Lager 1 · A'), 10, 1)->key());

    $synced = Activity::query()->where('action', Activity::SYNCED)->firstOrFail();
    expect($synced->label())->toBe('Opdateret fra Microbizz')
        ->and(app(UndoService::class)->window()->pluck('id'))->not->toContain($synced->id);

    expect(fn () => app(MoveService::class)->edit($stored, 'Andet', null, actor()))->toThrow(MoveRejected::class, 'Microbizz');

    // Ved hjemkomst kan kunden ikke skiftes for en container fra Microbizz.
    $moves = app(MoveService::class);
    $moves->deliver($stored, slot(row('Lager 1 · A'), 10, 1), actor());
    $moves->returnHome($stored->fresh(), slot(row('Lager 1 · A'), 10, 1), $other->id, actor());
    expect($stored->fresh()->customer_id)->toBe($local->id);
});

it('keeps a container on the board when it is removed in Microbizz, and hides one that is not', function () {
    $this->mb['tools'] = [tool(1, 'MSKU1'), tool(2, 'MSKU2')];
    ($this->import)();

    $onBoard = Container::query()->where('number', 'MSKU1')->firstOrFail();
    app(MoveService::class)->place($onBoard, slot(row('Lager 1 · B'), 10, 1), actor());

    $this->mb['tools'] = [tool(1, 'MSKU1', ['isdeleted' => 1]), tool(2, 'MSKU2', ['group' => 'Andet'])];
    $run = ($this->import)();

    expect($run->containers_removed)->toBe(2)
        ->and($onBoard->fresh()->microbizz_removed_at)->not->toBeNull()
        ->and($onBoard->fresh()->isStored())->toBeTrue()
        ->and(Container::query()->where('number', 'MSKU2')->exists())->toBeFalse();

    // Fortrudt i Microbizz: den skjulte kommer tilbage, og advarslen forsvinder.
    $this->mb['tools'] = [tool(1, 'MSKU1'), tool(2, 'MSKU2')];
    ($this->import)();

    expect($onBoard->fresh()->microbizz_removed_at)->toBeNull()
        ->and(Container::query()->where('number', 'MSKU2')->exists())->toBeTrue();
});

it('fetches a customer that is attached to a container but did not change', function () {
    // Kunden findes i Microbizz, men kommer ikke med blandt de ændrede.
    $this->mb['customers'] = [['id' => 77, 'customernumber' => 'K77', 'name' => 'Sen Kunde', 'isdeleted' => 0]];
    $this->mb['unchanged'] = true;
    $this->mb['tools'] = [tool(1, 'MSKU1', ['attachedcustomerid' => 77])];

    ($this->import)();

    expect(collect($this->mb['log'])->where('command', 'GetCustomerByID')->pluck('customerid')->all())->toBe([77])
        ->and(Container::query()->where('number', 'MSKU1')->first()->customer?->name)->toBe('Sen Kunde');
});

it('fetches only customers until it is chosen which equipment is containers', function () {
    Setting::put(MicrobizzSettings::FILTER_VALUE, '');
    $this->mb['tools'] = [tool(1, 'MSKU1')];

    $run = ($this->import)();

    expect($run->isOk())->toBeTrue()
        ->and(Container::query()->count())->toBe(0)
        ->and($run->notes[0])->toContain('vælg under Opsætning')
        ->and(collect($this->mb['log'])->pluck('command'))->not->toContain('GetToolsByLastChange');

    // Føreren får at vide, hvorfor der ikke kom nogen containere.
    session(['operator_id' => Operator::query()->create(['name' => 'Bente'])->id]);
    Livewire::test(Board::class)->call('syncMicrobizz')->assertSet('noticeType', 'error')
        ->assertSee('Kontoret skal først vælge under Opsætning');
});

it('records an error and keeps the starting point when Microbizz refuses', function () {
    ($this->import)();
    $since = Setting::get(MicrobizzSettings::TOOLS_SINCE);

    $this->mb['fail'] = true;
    $run = ($this->import)();

    expect($run->status)->toBe('error')
        ->and($run->error)->toBe('Microbizz afviste forespørgslen: Invalid API key')
        ->and(Setting::get(MicrobizzSettings::TOOLS_SINCE))->toBe($since);
});

it('is switched off until the access details are in .env', function () {
    config(['services.microbizz.apikey' => null]);

    expect(fn () => ($this->import)())->toThrow(MicrobizzException::class, 'ikke sat op');

    session(['operator_id' => Operator::query()->create(['name' => 'Bente'])->id]);
    Livewire::test(Board::class)->assertSee('Ny container')->assertDontSee('Hent nye fra Microbizz');
});

it('lets the forklift driver fetch new containers from the board instead of creating them', function () {
    session(['operator_id' => Operator::query()->create(['name' => 'Bente'])->id]);
    $this->mb['tools'] = [tool(1, 'MSKU1')];

    Livewire::test(Board::class)
        ->assertSee('Hent nye fra Microbizz')
        ->call('syncMicrobizz')
        ->assertSet('notice', 'Hentet fra Microbizz: 1 ny container.')
        ->set('newContainer.number', 'LOKAL1')
        ->call('createContainer')
        ->assertSet('noticeType', 'error');

    expect(Container::query()->where('number', 'LOKAL1')->exists())->toBeFalse()
        ->and(MicrobizzSync::query()->latest('id')->value('trigger'))->toBe('board');
});

it('saves the settings on the admin page and goes through all equipment again when they change', function () {
    $this->actingAs(User::factory()->create());
    ($this->import)();

    Livewire::test(Admin::class)
        ->set('tab', 'microbizz')
        ->assertSee('Slået til')
        ->set('mbForm.new_status', Container::DELIVERED)
        ->call('saveMicrobizz')
        ->assertSet('notice', 'Gemt.');

    expect(Setting::get(MicrobizzSettings::TOOLS_SINCE))->not->toBeNull();

    Livewire::test(Admin::class)
        ->set('tab', 'microbizz')
        ->set('mbForm.number_field', 'barcode')
        ->call('saveMicrobizz')
        ->assertSet('notice', 'Gemt. Alt udstyr gennemgås forfra ved næste hentning.');

    expect(Setting::get(MicrobizzSettings::TOOLS_SINCE))->toBeNull()
        ->and(MicrobizzSettings::newStatus())->toBe(Container::DELIVERED);

    Livewire::test(Admin::class)
        ->set('tab', 'containers')
        ->set('containerForm.number', 'LOKAL1')
        ->call('createContainer')
        ->assertSet('noticeType', 'error');
});
