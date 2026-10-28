<?php

namespace App\Services;

use App\Models\Container;
use App\Models\StorageRow;
use App\Support\Slot;

final class LocationLabel
{
    /** @var array<int, StorageRow> */
    private static array $rows = [];

    public static function for(string $status, ?Slot $slot): string
    {
        if ($status !== Container::STORED || $slot === null) {
            return Container::STATUS_LABELS[$status] ?? $status;
        }

        $row = self::$rows[$slot->rowId] ??= StorageRow::query()->with('zone.warehouse')->findOrFail($slot->rowId);

        if ($row->depth === 1) {
            return "{$row->label()} · højde {$slot->level}";
        }

        return "{$row->label()} · dybde {$slot->depth}, højde {$slot->level}";
    }

    public static function forContainer(Container $container): string
    {
        return self::for($container->status, $container->slot());
    }

    /** Glemmer cachede rækkenavne, f.eks. efter en ændring af layoutet. */
    public static function flush(): void
    {
        self::$rows = [];
    }
}
