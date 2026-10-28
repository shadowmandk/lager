<?php

namespace App\Services;

use App\Models\Container;
use App\Models\StorageRow;
use App\Models\Warehouse;
use App\Models\Zone;
use RuntimeException;

/**
 * Ændringer af lagre, områder og rækker. Pladser med containere kan aldrig forsvinde.
 */
final class LayoutService
{
    public function updateRow(StorageRow $row, string $name, int $depth, int $height): void
    {
        $this->assertSize($depth, $height);

        $outside = Container::query()
            ->where('status', Container::STORED)
            ->where('storage_row_id', $row->id)
            ->where(fn ($q) => $q->where('depth', '>', $depth)->orWhere('level', '>', $height))
            ->pluck('number');

        if ($outside->isNotEmpty()) {
            throw new RuntimeException(
                'Rækken kan ikke gøres mindre, fordi disse containere står på pladser, der ville forsvinde: '
                .$outside->implode(', ').'.'
            );
        }

        $row->update(['name' => trim($name), 'depth' => $depth, 'height' => $height]);
        LocationLabel::flush();
    }

    public function addRow(Zone $zone, string $name, int $depth, int $height): StorageRow
    {
        $this->assertSize($depth, $height);

        return $zone->rows()->create([
            'name' => trim($name),
            'depth' => $depth,
            'height' => $height,
            'sort' => (int) $zone->rows()->max('sort') + 1,
        ]);
    }

    public function deleteRow(StorageRow $row): void
    {
        if ($this->storedIn([$row->id]) > 0) {
            throw new RuntimeException("Række {$row->name} har stadig containere og kan ikke slettes.");
        }

        $row->delete();
    }

    public function deleteZone(Zone $zone): void
    {
        if ($this->storedIn($zone->rows()->pluck('id')->all()) > 0) {
            throw new RuntimeException("Området {$zone->name} har stadig containere og kan ikke slettes.");
        }

        $zone->delete();
    }

    public function deleteWarehouse(Warehouse $warehouse): void
    {
        $rowIds = StorageRow::query()->whereIn('zone_id', $warehouse->zones()->pluck('id'))->pluck('id')->all();

        if ($this->storedIn($rowIds) > 0) {
            throw new RuntimeException("{$warehouse->name} har stadig containere og kan ikke slettes.");
        }

        $warehouse->delete();
    }

    private function storedIn(array $rowIds): int
    {
        return Container::query()->where('status', Container::STORED)->whereIn('storage_row_id', $rowIds)->count();
    }

    private function assertSize(int $depth, int $height): void
    {
        if ($depth < 1 || $depth > 30 || $height < 1 || $height > 6) {
            throw new RuntimeException('Dybden skal være mellem 1 og 30, og højden mellem 1 og 6.');
        }
    }
}
