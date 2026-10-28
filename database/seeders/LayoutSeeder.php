<?php

namespace Database\Seeders;

use App\Models\Warehouse;
use Illuminate\Database\Seeder;

/**
 * Startlayoutet: Lager 1 med 3 rækker (10 dyb, 3 høj) og buffer (4 stakke, 3 høj),
 * Lager 2 med 3 rækker (6 dyb, 3 høj). Kan rettes under opsætning bagefter.
 */
class LayoutSeeder extends Seeder
{
    public function run(): void
    {
        if (Warehouse::query()->exists()) {
            return;
        }

        $lager1 = Warehouse::query()->create(['name' => 'Lager 1', 'sort' => 1]);

        $rows = $lager1->zones()->create(['name' => 'Rækker', 'sort' => 1]);
        foreach (['A', 'B', 'C'] as $i => $name) {
            $rows->rows()->create(['name' => $name, 'depth' => 10, 'height' => 3, 'sort' => $i + 1]);
        }

        $buffer = $lager1->zones()->create(['name' => 'Buffer', 'is_buffer' => true, 'sort' => 2]);
        foreach (range(1, 4) as $i) {
            $buffer->rows()->create(['name' => "Buffer {$i}", 'depth' => 1, 'height' => 3, 'sort' => $i]);
        }

        $lager2 = Warehouse::query()->create(['name' => 'Lager 2', 'sort' => 2]);

        $rows2 = $lager2->zones()->create(['name' => 'Rækker', 'sort' => 1]);
        foreach (['A', 'B', 'C'] as $i => $name) {
            $rows2->rows()->create(['name' => $name, 'depth' => 6, 'height' => 3, 'sort' => $i + 1]);
        }
    }
}
