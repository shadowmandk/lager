<?php

namespace App\Services;

use App\Models\Container;
use App\Models\StorageRow;
use App\Support\Slot;

/**
 * Tekster for, hvor en container er. Pladser hedder f.eks. "Lager 1 · A 3 2":
 * række A, plads 3 talt fra indgangen, højde 2 talt nedefra.
 */
final class LocationLabel
{
    /** @var array<int, StorageRow> */
    private static array $rows = [];

    public static function for(string $status, ?Slot $slot): string
    {
        if (! in_array($status, Container::ON_SLOT, true) || $slot === null) {
            return Container::STATUS_LABELS[$status] ?? $status;
        }

        $place = self::place($slot);

        return $status === Container::TRANSIT ? "I transit til {$place}" : $place;
    }

    /** "Lager 1 · A 3 2" */
    public static function place(Slot $slot): string
    {
        $row = self::row($slot->rowId);

        return $row->zone->warehouse->name.' · '.$row->slotName($slot->depth, $slot->level);
    }

    public static function forContainer(Container $container): string
    {
        return self::for($container->status, $container->location());
    }

    /** Glemmer cachede rækkenavne, f.eks. efter en ændring af layoutet. */
    public static function flush(): void
    {
        self::$rows = [];
    }

    private static function row(int $rowId): StorageRow
    {
        return self::$rows[$rowId] ??= StorageRow::query()->with('zone.warehouse')->findOrFail($rowId);
    }
}
