<?php

namespace App\Livewire;

use App\Models\Container;
use App\Models\Customer;
use App\Models\StorageRow;
use App\Models\Warehouse;
use App\Services\AccessCalculator;
use App\Services\Actor;
use App\Services\LocationLabel;
use App\Services\MoveRejected;
use App\Services\MoveService;
use App\Services\PlacementRules;
use App\Services\RowGrid;
use App\Services\TransferPlanner;
use App\Services\WorkPlanner;
use App\Support\BoardVersion;
use App\Support\Slot;
use InvalidArgumentException;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Title('Tavle')]
class Board extends Component
{
    private const PLAN = 'workplan';

    #[Url(as: 'lager')]
    public ?int $warehouseId = null;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    /** Container valgt med et tryk; lovlige pladser lyser, og et tryk på en af dem flytter den. */
    public ?int $picked = null;

    /** Indholdet af bekræftelsesboksen, når en flytning venter på "Bekræft". */
    public ?array $confirm = null;

    public ?int $returnCustomerId = null;

    /** Formularen "Ny container" er åben. */
    public bool $creating = false;

    /** @var array{number: string, name: string, customer_id: string} */
    public array $newContainer = ['number' => '', 'name' => '', 'customer_id' => ''];

    public ?int $panelId = null;

    public string $editName = '';

    public ?int $editCustomerId = null;

    public ?string $notice = null;

    public string $noticeType = 'ok';

    public int $noticeId = 0;

    /** Planvisningen for arbejdsplanen er åben. */
    public bool $planOpen = false;

    /** Containeren i planen, hvis andre pladser vises. */
    public ?int $planEdit = null;

    public function mount(): void
    {
        $this->warehouseId ??= Warehouse::query()->orderBy('sort')->orderBy('id')->value('id');
    }

    public function selectWarehouse(int $id): void
    {
        $this->warehouseId = $id;
    }

    public function updatedSearch(): void
    {
        $this->search = mb_substr($this->search, 0, 60);
    }

    public function clearSearch(): void
    {
        $this->search = '';
    }

    public function pick(int $containerId): void
    {
        $container = Container::query()->find($containerId);

        if ($container === null) {
            $this->flash('Containeren findes ikke længere.', 'error');

            return;
        }

        if ($container->occupiesSlot() && ($reason = app(PlacementRules::class)->whyCannotTake($container))) {
            $this->flash($reason, 'error');

            return;
        }

        $this->picked = $container->id;
        $this->panelId = null;
    }

    public function cancelPick(): void
    {
        $this->picked = null;
    }

    public function openCreate(): void
    {
        $this->resetValidation();
        $this->newContainer = ['number' => '', 'name' => '', 'customer_id' => ''];
        $this->creating = true;
        $this->panelId = null;
    }

    public function cancelCreate(): void
    {
        $this->creating = false;
    }

    /**
     * Opret en container, der lige er kommet ind. Den venter på plads og er valgt med det samme,
     * så de grønne pladser lyser og den kan sættes med ét tryk.
     */
    public function createContainer(): void
    {
        $data = $this->validate([
            'newContainer.number' => ['required', 'string', 'max:30'],
            'newContainer.name' => ['nullable', 'string', 'max:100'],
            'newContainer.customer_id' => ['nullable', 'integer', 'exists:customers,id'],
        ], [], [
            'newContainer.number' => 'containernummer',
            'newContainer.name' => 'navn',
            'newContainer.customer_id' => 'kunde',
        ])['newContainer'];

        try {
            $container = app(MoveService::class)->create(
                $data['number'], $data['name'] ?? null, $this->validCustomerId((int) ($data['customer_id'] ?? 0)),
                Container::PENDING, $this->actor(),
            );
        } catch (MoveRejected $e) {
            $this->addError('newContainer.number', $e->getMessage());

            return;
        }

        $this->creating = false;
        $this->picked = $container->id;
        $this->flash("Container {$container->number} er oprettet. Tryk på en grøn plads for at sætte den, eller Annullér for at lade den vente.");
    }

    /**
     * Forbered en flytning og vis bekræftelsesboksen. Kaldes ved slip efter træk eller ved tryk på en grøn plads.
     *
     * @param  string|null  $fromKey  hvor skærmen troede containeren stod
     * @param  string  $target  en pladsnøgle "række:dybde:højde" eller "deliver"
     */
    public function prepare(int $containerId, ?string $fromKey, string $target): void
    {
        $rules = app(PlacementRules::class);
        $container = Container::query()->with('customer')->find($containerId);

        if ($container === null) {
            $this->flash('Containeren findes ikke længere.', 'error');
            $this->picked = null;

            return;
        }

        if ($container->isInTransit()) {
            $this->flash($rules->whyCannotTake($container), 'error');
            $this->picked = null;

            return;
        }

        if ($fromKey !== null && $fromKey !== $container->slot()?->key()) {
            $this->flash(MoveRejected::boardChanged()->getMessage(), 'error');
            $this->picked = null;

            return;
        }

        $warnings = [];

        if ($target === 'deliver') {
            $type = 'deliver';
            $reason = $container->isStored() ? $rules->whyCannotTake($container) : 'Containeren står ikke på lager.';
            $toLabel = Container::STATUS_LABELS[Container::DELIVERED];
        } else {
            try {
                $slot = Slot::fromKey($target);
            } catch (InvalidArgumentException) {
                return;
            }

            if ($slot->equals($container->slot())) {
                return;
            }

            [$type, $reason] = match ($container->status) {
                Container::STORED => ['move', $rules->whyCannotTake($container) ?? $rules->whyCannotPlace($slot, $container->id)],
                Container::DELIVERED => ['return', $rules->whyCannotPlace($slot)],
                default => ['place', $rules->whyCannotPlace($slot)],
            };

            if ($reason === null) {
                $warnings = $rules->warnings($slot, $container->isStored() ? $container->id : null);
            }

            $toLabel = $reason === null ? LocationLabel::for(Container::STORED, $slot) : '';
        }

        if ($reason !== null) {
            $this->flash($reason, 'error');

            return;
        }

        $this->confirm = [
            'type' => $type,
            'id' => $container->id,
            'number' => $container->number,
            'name' => $container->name,
            'customer' => $container->customer?->name,
            'from' => $container->slot()?->key(),
            'fromLabel' => LocationLabel::forContainer($container),
            'target' => $target,
            'toLabel' => $toLabel,
            'warnings' => $warnings,
        ];
        $this->returnCustomerId = $container->customer_id;
    }

    /** Forbered "Flyt til Lager 2": systemet vælger bufferpladsen, og bekræftelsen viser den. */
    public function prepareTransfer(int $containerId, ?string $fromKey, int $warehouseId): void
    {
        $container = Container::query()->with('customer')->find($containerId);
        $warehouse = Warehouse::query()->find($warehouseId);

        if ($container === null || $warehouse === null) {
            $this->flash('Containeren eller lageret findes ikke længere.', 'error');

            return;
        }

        if (! $container->isStored() || $fromKey !== $container->slot()?->key()) {
            $this->flash(MoveRejected::boardChanged()->getMessage(), 'error');

            return;
        }

        $plan = app(TransferPlanner::class)->plan($container, $warehouse);

        if ($plan['slot'] === null) {
            $this->flash($plan['reason'], 'error');

            return;
        }

        $this->panelId = null;
        $this->picked = null;
        $this->confirm = [
            'type' => 'transfer',
            'id' => $container->id,
            'number' => $container->number,
            'name' => $container->name,
            'customer' => $container->customer?->name,
            'from' => $container->slot()->key(),
            'fromLabel' => LocationLabel::forContainer($container),
            'target' => $plan['slot']->key(),
            'toLabel' => $plan['label'],
            'warehouse' => $warehouse->name,
            'warnings' => [],
        ];
    }

    /** Forbered "Sat i buffer" for en container i transit. */
    public function prepareArrive(int $containerId): void
    {
        $container = Container::query()->with('customer')->find($containerId);

        if ($container === null || ! $container->isInTransit()) {
            $this->flash(MoveRejected::boardChanged()->getMessage(), 'error');

            return;
        }

        $this->panelId = null;
        $this->picked = null;
        $this->confirm = [
            'type' => 'arrive',
            'id' => $container->id,
            'number' => $container->number,
            'name' => $container->name,
            'customer' => $container->customer?->name,
            'from' => $container->location()->key(),
            'fromLabel' => Container::STATUS_LABELS[Container::TRANSIT],
            'target' => $container->location()->key(),
            'toLabel' => LocationLabel::place($container->location()),
            'warnings' => [],
        ];
    }

    /**
     * Et slip uden for de grønne pladser. Er der sluppet på en stak, siges det, hvorfor den ikke kan bruges.
     *
     * @param  string|null  $column  "rækkeId:dybde" for den stak, der blev sluppet på
     */
    public function illegalDrop(?int $containerId = null, ?string $column = null): void
    {
        $this->flash($this->illegalDropReason($containerId, $column), 'error');
    }

    private function illegalDropReason(?int $containerId, ?string $column): string
    {
        $fallback = 'Det er ikke en lovlig plads. Kun de grønne pladser kan bruges.';

        if ($column === null || ! preg_match('/^(\d+):(\d+)$/', $column, $m)) {
            return $fallback;
        }

        $row = StorageRow::query()->find((int) $m[1]);
        $depth = (int) $m[2];

        if ($row === null || $depth < 1 || $depth > $row->depth) {
            return $fallback;
        }

        $grid = RowGrid::load($row)->without($containerId);
        $count = $grid->countInColumn($depth);

        if ($count >= $row->height) {
            return 'Stakken er fuld. Vælg en af de grønne pladser.';
        }

        $reason = $grid->whyCannotPlace($depth, $count + 1);

        return $reason === null ? $fallback : $reason.' Vælg en af de grønne pladser.';
    }

    public function cancelConfirm(): void
    {
        $this->confirm = null;
    }

    public function confirmMove(): void
    {
        if ($this->confirm === null) {
            return;
        }

        $c = $this->confirm;
        $this->confirm = null;
        $this->picked = null;

        $container = Container::query()->find($c['id']);

        if ($container === null) {
            $this->flash('Containeren findes ikke længere.', 'error');

            return;
        }

        $moves = app(MoveService::class);
        $actor = $this->actor();

        try {
            $from = $c['from'] ? Slot::fromKey($c['from']) : null;

            match ($c['type']) {
                'move' => $moves->move($container, $from ?? throw MoveRejected::boardChanged(), Slot::fromKey($c['target']), $actor),
                'deliver' => $moves->deliver($container, $from ?? throw MoveRejected::boardChanged(), $actor),
                'return' => $moves->returnHome($container, Slot::fromKey($c['target']), $this->validCustomerId($this->returnCustomerId), $actor),
                'place' => $moves->place($container, Slot::fromKey($c['target']), $actor),
                'transfer' => $moves->transfer($container, $from ?? throw MoveRejected::boardChanged(), Slot::fromKey($c['target']), $actor),
                'arrive' => $moves->arrive($container, Slot::fromKey($c['target']), $actor),
            };

            $this->flash(match ($c['type']) {
                'deliver' => "Container {$c['number']} er udleveret.",
                'transfer' => "Container {$c['number']} er i transit til {$c['toLabel']}. Tryk “Sat i buffer”, når den står der.",
                default => "Container {$c['number']} står nu på {$c['toLabel']}.",
            });
        } catch (MoveRejected $e) {
            $this->flash($e->getMessage(), 'error');
        }
    }

    public function openPanel(int $containerId): void
    {
        $container = Container::query()->with('row.zone')->find($containerId);

        if ($container === null) {
            return;
        }

        if ($container->occupiesSlot()) {
            $this->warehouseId = $container->row->zone->warehouse_id;
        }

        $this->panelId = $container->id;
        $this->editName = (string) $container->name;
        $this->editCustomerId = $container->customer_id;
    }

    public function closePanel(): void
    {
        $this->panelId = null;
    }

    public function savePanel(): void
    {
        $container = Container::query()->find($this->panelId);

        if ($container === null) {
            return;
        }

        $this->validate([
            'editName' => ['nullable', 'string', 'max:100'],
            'editCustomerId' => ['nullable', 'integer', 'exists:customers,id'],
        ]);

        $log = app(MoveService::class)->edit($container, $this->editName, $this->editCustomerId, $this->actor());

        $this->flash($log ? "Container {$container->number} er rettet." : 'Ingen ændringer.');
    }

    /**
     * Arbejdsplan: forslag til, hvor containerne i vejen skal stå. Planen gemmes i tablettens session
     * som målcontainer og de pladser, medarbejderen selv har valgt, og regnes forfra ud fra tavlen hver gang.
     */
    public function openPlan(int $containerId): void
    {
        $container = Container::query()->with('row.zone')->find($containerId);

        if ($container === null || ! $container->isStored()) {
            $this->flash('Containeren står ikke på lageret.', 'error');

            return;
        }

        $current = $this->planState();

        if ($current === null || $current['target'] !== $container->id) {
            $plan = app(WorkPlanner::class)->plan($container);

            if ($plan === null || $plan['blockers'] === 0) {
                $this->flash("{$container->number} kan tages nu. Der skal ikke flyttes noget først.");

                return;
            }

            if ($current !== null) {
                $old = Container::query()->find($current['target']);
                $this->flash('Den tidligere arbejdsplan'.($old ? " for {$old->number}" : '').' er stoppet.');
            }

            session([self::PLAN => ['target' => $container->id, 'overrides' => [], 'started' => false, 'total' => $plan['blockers']]]);
        }

        $this->warehouseId = $container->row->zone->warehouse_id;
        $this->planOpen = true;
        $this->planEdit = null;
        $this->panelId = null;
        $this->picked = null;
    }

    public function editStep(int $containerId): void
    {
        $this->planEdit = $this->planEdit === $containerId ? null : $containerId;
    }

    public function chooseStep(int $containerId, string $key): void
    {
        $state = $this->planState();

        try {
            Slot::fromKey($key);
        } catch (InvalidArgumentException) {
            return;
        }

        if ($state === null) {
            return;
        }

        $state['overrides'][$containerId] = $key;
        session([self::PLAN => $state]);
        $this->planEdit = null;
    }

    public function resetStep(int $containerId): void
    {
        $state = $this->planState();

        if ($state === null) {
            return;
        }

        unset($state['overrides'][$containerId]);
        session([self::PLAN => $state]);
        $this->planEdit = null;
    }

    public function startPlan(): void
    {
        $state = $this->planState();
        $plan = $state ? $this->currentPlan($state) : null;

        if ($plan === null) {
            return;
        }

        if ($plan['problem'] !== null) {
            $this->flash($plan['problem'], 'error');

            return;
        }

        $state['started'] = true;
        $state['total'] = count($plan['steps']);
        session([self::PLAN => $state]);

        $this->planOpen = false;
        $this->planEdit = null;
        $this->flash('Planen er startet. Følg trinene nederst på skærmen, og tryk “Udført” efter hvert træk.');
    }

    public function showPlan(?int $editContainerId = null): void
    {
        if ($this->planState() !== null) {
            $this->planOpen = true;
            $this->planEdit = $editContainerId;
        }
    }

    /** Lukker planvisningen. En plan, der ikke er startet, glemmes. */
    public function closePlan(): void
    {
        $state = $this->planState();

        if ($state !== null && ! $state['started']) {
            session()->forget(self::PLAN);
        }

        $this->planOpen = false;
        $this->planEdit = null;
    }

    public function stopPlan(): void
    {
        session()->forget(self::PLAN);
        $this->planOpen = false;
        $this->planEdit = null;
        $this->flash('Arbejdsplanen er stoppet. De flyttede containere står, hvor de blev sat.');
    }

    /**
     * Næste trin er udført. Trinet sendes med, så et dobbelttryk eller en ændret tavle ikke flytter
     * en anden container end den, medarbejderen så på skærmen.
     */
    public function stepDone(int $containerId, string $fromKey, string $toKey): void
    {
        $state = $this->planState();
        $plan = $state ? $this->currentPlan($state) : null;
        $step = $plan['steps'][0] ?? null;

        if ($step === null
            || $step['container']->id !== $containerId
            || $step['from']->key() !== $fromKey
            || $step['to']?->key() !== $toKey) {
            $this->flash('Planen har ændret sig, siden du trykkede. Se det nye trin, før du trykker igen.', 'error');

            return;
        }

        try {
            app(MoveService::class)->move($step['container'], $step['from'], $step['to'], $this->actor());
        } catch (MoveRejected $e) {
            $this->flash($e->getMessage(), 'error');

            return;
        }

        if (count($plan['steps']) === 1) {
            $this->finishPlan();

            return;
        }

        $this->flash("{$step['container']->number} står nu på {$step['label']}.");
    }

    /** Alle containere i vejen er flyttet: planen er slut, og målcontaineren åbnes med de kendte knapper. */
    public function finishPlan(): void
    {
        $state = $this->planState();
        session()->forget(self::PLAN);
        $this->planOpen = false;
        $this->planEdit = null;

        $target = $state ? Container::query()->find($state['target']) : null;

        if ($target === null) {
            return;
        }

        $this->openPanel($target->id);
        $this->flash("Vejen er fri. {$target->number} kan tages nu.");
    }

    public function render()
    {
        $work = $this->workPlanData();

        $warehouses = Warehouse::query()->orderBy('sort')->orderBy('id')->get();
        $warehouse = $warehouses->firstWhere('id', $this->warehouseId) ?? $warehouses->first();
        $this->warehouseId = $warehouse?->id;
        $warehouse?->load('zones.rows.zone.warehouse');

        $rows = $warehouse ? $warehouse->zones->flatMap->rows : collect();

        $stored = Container::query()
            ->with('customer')
            ->whereIn('status', Container::ON_SLOT)
            ->whereIn('storage_row_id', $rows->pluck('id'))
            ->get()
            ->groupBy('storage_row_id');

        $grids = $rows->mapWithKeys(fn ($row) => [$row->id => RowGrid::fromContainers($row, $stored->get($row->id, []))]);

        $picked = $this->resolvePicked();

        $dragRows = [];
        $pickTargets = [];

        foreach ($grids as $rowId => $grid) {
            $take = $grid->takeable();
            $base = $this->keys($rowId, $grid->legalTargets());
            $lift = $take
                ? array_values(array_diff($this->keys($rowId, $grid->without($take->id)->legalTargets()), [$take->slot()->key()]))
                : [];

            $dragRows[$rowId] = ['base' => $base, 'lift' => $lift];

            if ($picked) {
                $own = $picked->isStored() && $picked->storage_row_id === $rowId;
                array_push($pickTargets, ...($own ? $lift : $base));
            }
        }

        $term = trim($this->search);
        $results = $term === ''
            ? collect()
            : Container::query()->with('customer', 'row.zone.warehouse')->search($term)->orderBy('number')->limit(40)->get();
        $matches = $term === '' ? null : $results->pluck('id')->flip()->all();

        $listQuery = fn (string $status) => Container::query()
            ->with('customer')
            ->where('status', $status)
            ->search($term)
            ->orderBy('number');

        return view('livewire.board', [
            'warehouses' => $warehouses,
            'warehouse' => $warehouse,
            'grids' => $grids,
            'dragRows' => $dragRows,
            'pickedContainer' => $picked,
            'pickTargets' => array_flip($pickTargets),
            'matches' => $matches,
            'results' => $results,
            'pendingList' => $listQuery(Container::PENDING)->get(),
            'transitList' => Container::query()->with('customer')->where('status', Container::TRANSIT)->orderBy('updated_at')->get(),
            'deliveredList' => $listQuery(Container::DELIVERED)->limit(300)->get(),
            'deliveredCount' => Container::query()->where('status', Container::DELIVERED)->count(),
            'customers' => Customer::query()->orderBy('name')->get(['id', 'name', 'number']),
            'panel' => $this->panelData(),
            'work' => $work,
            'planStep' => $work['current'] ?? null,
            'version' => BoardVersion::current(),
        ]);
    }

    /** @return array{target: int, overrides: array<int, string>, started: bool, total: int}|null */
    private function planState(): ?array
    {
        $state = session(self::PLAN);

        if (! is_array($state) || ! is_int($state['target'] ?? null)) {
            return null;
        }

        return [
            'target' => $state['target'],
            'overrides' => array_filter((array) ($state['overrides'] ?? []), 'is_string'),
            'started' => (bool) ($state['started'] ?? false),
            'total' => (int) ($state['total'] ?? 0),
        ];
    }

    /** Planen regnet ud fra tavlen nu. Valg, der ikke længere kan bruges, fjernes med en besked. */
    private function currentPlan(array $state): ?array
    {
        $target = Container::query()->find($state['target']);
        $plan = $target ? app(WorkPlanner::class)->plan($target, $state['overrides']) : null;

        if ($plan !== null && $plan['dropped'] !== []) {
            $numbers = Container::query()->whereKey($plan['dropped'])->orderBy('number')->pluck('number')->join(', ', ' og ');

            foreach ($plan['dropped'] as $id) {
                unset($state['overrides'][$id]);
            }

            session([self::PLAN => $state]);
            $this->flash("Pladsen, du valgte til {$numbers}, er ikke ledig længere. Planen foreslår en ny.", 'error');
        }

        return $plan;
    }

    /** @return array<string, mixed>|null */
    private function workPlanData(): ?array
    {
        $state = $this->planState();

        if ($state === null) {
            $this->planOpen = false;

            return null;
        }

        $target = Container::query()->with('customer', 'row.zone')->find($state['target']);
        $plan = $this->currentPlan($state);

        if ($target === null || $plan === null) {
            session()->forget(self::PLAN);
            $this->planOpen = false;
            $this->planEdit = null;
            $this->flash('Arbejdsplanen er stoppet, fordi '.($target?->number ?? 'containeren').' ikke længere står på lageret.', 'error');

            return null;
        }

        $total = max($state['total'], $plan['blockers']);
        $done = $total - $plan['blockers'];
        $first = $plan['steps'][0] ?? null;

        return [
            'target' => $target,
            'targetLabel' => $target->row->slotName($target->depth, $target->level),
            'plan' => $plan,
            'started' => $state['started'],
            'done' => $done,
            'total' => $total,
            'current' => $state['started'] && $first && $first['to']
                ? ['id' => $first['container']->id, 'to' => $first['to']->key(), 'n' => $done + 1]
                : null,
        ];
    }

    private function resolvePicked(): ?Container
    {
        if ($this->picked === null) {
            return null;
        }

        $picked = Container::query()->with('customer')->find($this->picked);

        if ($picked === null || ($picked->occupiesSlot() && ! app(PlacementRules::class)->canTake($picked))) {
            $this->picked = null;

            return null;
        }

        return $picked;
    }

    /** @return array<string, mixed>|null */
    private function panelData(): ?array
    {
        if ($this->panelId === null) {
            return null;
        }

        $container = Container::query()->with('customer', 'row.zone.warehouse')->find($this->panelId);

        if ($container === null) {
            $this->panelId = null;

            return null;
        }

        return [
            'container' => $container,
            'location' => LocationLabel::forContainer($container),
            'access' => app(AccessCalculator::class)->for($container),
            'takeReason' => $container->isStored() ? app(PlacementRules::class)->whyCannotTake($container) : null,
            'transfers' => $container->isStored() ? app(TransferPlanner::class)->options($container) : collect(),
            'planActive' => ($this->planState()['target'] ?? null) === $container->id,
            'history' => $container->activities()->with('operator', 'user')->limit(8)->get(),
        ];
    }

    /** @param list<array{0: int, 1: int}> $targets */
    private function keys(int $rowId, array $targets): array
    {
        return array_map(fn ($t) => "{$rowId}:{$t[0]}:{$t[1]}", $targets);
    }

    private function actor(): Actor
    {
        return Actor::operator((int) session('operator_id'));
    }

    private function validCustomerId(?int $id): ?int
    {
        return $id && Customer::query()->whereKey($id)->exists() ? $id : null;
    }

    private function flash(string $message, string $type = 'ok'): void
    {
        $this->notice = $message;
        $this->noticeType = $type;
        $this->noticeId++;
    }
}
