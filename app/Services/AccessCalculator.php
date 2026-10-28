<?php

namespace App\Services;

use App\Models\Container;
use App\Models\Setting;
use App\Models\StorageRow;

/**
 * "Kom ind til": hvad koster det at få fat i en bestemt container?
 * Ændrer intet; det er et opslag til planlægning.
 */
final class AccessCalculator
{
    /**
     * @return array{
     *     blockers: list<Container>,
     *     moves: int,
     *     minutes: int,
     *     minutes_per_move: int,
     *     buffer_free: int,
     *     warehouse_free: int,
     * }|null
     */
    public function for(Container $container): ?array
    {
        if (! $container->isStored()) {
            return null;
        }

        $row = StorageRow::query()->with('zone')->findOrFail($container->storage_row_id);
        $blockers = RowGrid::load($row)->blockersFor($container);

        $bufferFree = 0;
        $warehouseFree = 0;

        $otherRows = StorageRow::query()
            ->with('zone')
            ->whereHas('zone', fn ($q) => $q->where('warehouse_id', $row->zone->warehouse_id))
            ->whereKeyNot($row->id)
            ->get();

        $stored = Container::query()
            ->where('status', Container::STORED)
            ->whereIn('storage_row_id', $otherRows->pluck('id'))
            ->get()
            ->groupBy('storage_row_id');

        foreach ($otherRows as $other) {
            $free = RowGrid::fromContainers($other, $stored->get($other->id, []))->reachableFree();

            if ($other->zone->is_buffer) {
                $bufferFree += $free;
            } else {
                $warehouseFree += $free;
            }
        }

        $perMove = Setting::minutesPerMove();
        $moves = count($blockers);

        return [
            'blockers' => $blockers,
            'moves' => $moves,
            'minutes' => $moves * $perMove,
            'minutes_per_move' => $perMove,
            'buffer_free' => $bufferFree,
            'warehouse_free' => $warehouseFree,
        ];
    }
}
