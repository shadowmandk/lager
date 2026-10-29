<?php

namespace App\Services;

use App\Models\Container;
use App\Models\StorageRow;
use App\Models\Warehouse;
use App\Support\Slot;
use Illuminate\Support\Collection;

/**
 * Flytning mellem lagre: finder den bufferplads på det andet lager, containeren skal stå på.
 *
 * Valget er den lovlige bufferplads, der ikke spærrer en ledig container inde, spærrer færrest
 * tomme pladser inde og står lavest, så stakkene holdes lave. Ved lighed vælges den første i
 * opsætningens rækkefølge.
 */
final class TransferPlanner
{
    public function __construct(private readonly PlacementRules $rules) {}

    /**
     * Et forslag pr. andet lager, til knapperne i panelet.
     *
     * @return Collection<int, array{warehouse: Warehouse, slot: ?Slot, label: ?string, reason: ?string}>
     */
    public function options(Container $container): Collection
    {
        $current = $this->warehouseIdOf($container);

        return Warehouse::query()->orderBy('sort')->orderBy('id')->get()
            ->reject(fn (Warehouse $w) => $w->id === $current)
            ->map(fn (Warehouse $w) => ['warehouse' => $w] + $this->plan($container, $w))
            ->values();
    }

    /** @return array{slot: ?Slot, label: ?string, reason: ?string} */
    public function plan(Container $container, Warehouse $to): array
    {
        $fail = fn (string $reason) => ['slot' => null, 'label' => null, 'reason' => $reason];

        if ($reason = $this->rules->whyCannotTake($container)) {
            return $fail($reason);
        }

        if ($this->warehouseIdOf($container) === $to->id) {
            return $fail("Containeren står allerede på {$to->name}.");
        }

        $rows = $this->bufferRows($to);

        if ($rows->isEmpty()) {
            return $fail("{$to->name} har ingen buffer. Den oprettes under opsætning.");
        }

        $best = null;
        $bestKey = null;

        foreach ($rows as $order => $row) {
            $grid = RowGrid::load($row);

            foreach ($grid->legalTargets() as [$depth, $level]) {
                $key = [$grid->trapsAvailable($depth, $level) ? 1 : 0, $grid->blockedBehind($depth), $level, $order, -$depth];

                if ($bestKey === null || $key < $bestKey) {
                    $best = new Slot($row->id, $depth, $level);
                    $bestKey = $key;
                }
            }
        }

        if ($best === null) {
            return $fail("Bufferen på {$to->name} er fuld.");
        }

        return ['slot' => $best, 'label' => LocationLabel::place($best), 'reason' => null];
    }

    /** Er pladsen en bufferplads på et andet lager end containerens? */
    public function isBufferElsewhere(Container $container, Slot $slot): bool
    {
        $row = StorageRow::query()->with('zone')->find($slot->rowId);

        return $row !== null
            && $row->zone->is_buffer
            && $row->zone->warehouse_id !== $this->warehouseIdOf($container);
    }

    /** @return Collection<int, StorageRow> */
    private function bufferRows(Warehouse $warehouse): Collection
    {
        return StorageRow::query()
            ->join('zones', 'zones.id', '=', 'storage_rows.zone_id')
            ->where('zones.warehouse_id', $warehouse->id)
            ->where('zones.is_buffer', true)
            ->orderBy('zones.sort')->orderBy('zones.id')
            ->orderBy('storage_rows.sort')->orderBy('storage_rows.id')
            ->select('storage_rows.*')
            ->get()
            ->values();
    }

    private function warehouseIdOf(Container $container): ?int
    {
        if ($container->storage_row_id === null) {
            return null;
        }

        return StorageRow::query()->with('zone')->find($container->storage_row_id)?->zone->warehouse_id;
    }
}
