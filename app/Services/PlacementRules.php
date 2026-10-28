<?php

namespace App\Services;

use App\Models\Container;
use App\Models\StorageRow;
use App\Support\Slot;

/**
 * Indgang til reglerne for, hvad der kan tages og sættes. Logikken bor i RowGrid.
 */
final class PlacementRules
{
    public function grid(StorageRow|int $row): RowGrid
    {
        $row = $row instanceof StorageRow ? $row : StorageRow::query()->findOrFail($row);

        return RowGrid::load($row);
    }

    public function whyCannotTake(Container $container): ?string
    {
        if (! $container->isStored()) {
            return 'Containeren står ikke på lager.';
        }

        $blockers = count($this->grid($container->storage_row_id)->blockersFor($container));

        return match ($blockers) {
            0 => null,
            1 => 'Containeren er spærret af 1 container.',
            default => "Containeren er spærret af {$blockers} containere.",
        };
    }

    public function canTake(Container $container): bool
    {
        return $this->whyCannotTake($container) === null;
    }

    public function whyCannotPlace(Slot $slot, ?int $ignoreContainerId = null): ?string
    {
        $row = StorageRow::query()->find($slot->rowId);

        if ($row === null) {
            return 'Rækken findes ikke.';
        }

        return RowGrid::load($row)->without($ignoreContainerId)->whyCannotPlace($slot->depth, $slot->level);
    }

    public function canPlace(Slot $slot, ?int $ignoreContainerId = null): bool
    {
        return $this->whyCannotPlace($slot, $ignoreContainerId) === null;
    }

    /** @return list<Slot> */
    public function legalTargets(StorageRow $row, ?int $ignoreContainerId = null): array
    {
        return array_map(
            fn (array $t) => new Slot($row->id, $t[0], $t[1]),
            RowGrid::load($row)->without($ignoreContainerId)->legalTargets(),
        );
    }

    public function blockedBehind(Slot $slot, ?int $ignoreContainerId = null): int
    {
        $row = StorageRow::query()->findOrFail($slot->rowId);

        return RowGrid::load($row)->without($ignoreContainerId)->blockedBehind($slot->depth);
    }

    /** @return list<string> advarsler, der skal vises i bekræftelsesboksen */
    public function warnings(Slot $slot, ?int $ignoreContainerId = null): array
    {
        $blocked = $this->blockedBehind($slot, $ignoreContainerId);

        return match (true) {
            $blocked === 1 => ['Du spærrer 1 tom plads bag ved.'],
            $blocked > 1 => ["Du spærrer {$blocked} tomme pladser bag ved."],
            default => [],
        };
    }
}
