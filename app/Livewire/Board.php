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
use App\Support\BoardVersion;
use App\Support\Slot;
use InvalidArgumentException;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Title('Tavle')]
class Board extends Component
{
    #[Url(as: 'lager')]
    public ?int $warehouseId = null;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    /** Container valgt med et tryk; lovlige pladser lyser, og et tryk på en af dem flytter den. */
    public ?int $picked = null;

    /** Indholdet af bekræftelsesboksen, når en flytning venter på "Bekræft". */
    public ?array $confirm = null;

    public ?int $returnCustomerId = null;

    public ?int $panelId = null;

    public string $editName = '';

    public ?int $editCustomerId = null;

    public ?string $notice = null;

    public string $noticeType = 'ok';

    public int $noticeId = 0;

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

        if ($container->isStored() && ($reason = app(PlacementRules::class)->whyCannotTake($container))) {
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
            };

            $this->flash(match ($c['type']) {
                'deliver' => "Container {$c['number']} er udleveret.",
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

        if ($container->isStored()) {
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

    public function render()
    {
        $warehouses = Warehouse::query()->orderBy('sort')->orderBy('id')->get();
        $warehouse = $warehouses->firstWhere('id', $this->warehouseId) ?? $warehouses->first();
        $this->warehouseId = $warehouse?->id;
        $warehouse?->load('zones.rows.zone.warehouse');

        $rows = $warehouse ? $warehouse->zones->flatMap->rows : collect();

        $stored = Container::query()
            ->with('customer')
            ->where('status', Container::STORED)
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
            'deliveredList' => $listQuery(Container::DELIVERED)->limit(300)->get(),
            'deliveredCount' => Container::query()->where('status', Container::DELIVERED)->count(),
            'customers' => Customer::query()->orderBy('name')->get(['id', 'name', 'number']),
            'panel' => $this->panelData(),
            'version' => BoardVersion::current(),
        ]);
    }

    private function resolvePicked(): ?Container
    {
        if ($this->picked === null) {
            return null;
        }

        $picked = Container::query()->with('customer')->find($this->picked);

        if ($picked === null || ($picked->isStored() && ! app(PlacementRules::class)->canTake($picked))) {
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
