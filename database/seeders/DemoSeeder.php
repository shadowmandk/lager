<?php

namespace Database\Seeders;

use App\Models\Container;
use App\Models\Customer;
use App\Models\Operator;
use App\Models\StorageRow;
use Illuminate\Database\Seeder;

/**
 * Fiktive demodata til udvikling og fremvisning. Køres ikke i produktion.
 * Containerne placeres lovligt: rækkerne fyldes bagfra, og stakke bygges nedefra.
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (Container::query()->exists()) {
            return;
        }

        foreach (['Anders', 'Bente', 'Carsten', 'Dorthe'] as $name) {
            Operator::query()->create(['name' => $name]);
        }

        $customers = collect([
            ['10021', 'Jensen Byg ApS'],
            ['10034', 'Nordic Flyt'],
            ['10057', 'Hansen & Søn'],
            ['10062', 'Fjordens Event'],
            ['10078', 'Kystens VVS'],
            ['10085', 'Midtjysk Maskin'],
            ['10093', 'Lindholm Arkiv'],
            ['10104', 'Østergaard Have'],
        ])->map(fn ($c) => Customer::query()->create(['number' => $c[0], 'name' => $c[1]]));

        $names = ['Værktøj', 'Møbler', 'Arkiv', 'Lager', 'Inventar', 'Materialer', 'Udstyr', 'Sæsonvarer'];
        $number = 4001;

        $make = function (array $attrs) use (&$number, $customers, $names) {
            $customer = $customers->random();

            return Container::query()->create($attrs + [
                'number' => (string) $number++,
                'name' => $names[array_rand($names)],
                'customer_id' => $customer->id,
            ]);
        };

        // Hvor mange kolonner bagfra der fyldes i hver række, og hvor mange der står i den forreste.
        $fill = [
            'Lager 1 · A' => [7, 2],
            'Lager 1 · B' => [5, 1],
            'Lager 1 · C' => [8, 3],
            'Lager 1 · Buffer 1' => [0, 2],
            'Lager 1 · Buffer 3' => [0, 1],
            'Lager 2 · A' => [4, 2],
            'Lager 2 · B' => [2, 0],
        ];

        $rows = StorageRow::query()->with('zone.warehouse')->get()->keyBy(fn (StorageRow $r) => $r->label());

        foreach ($fill as $label => [$fullColumns, $front]) {
            $row = $rows[$label] ?? null;

            if (! $row) {
                continue;
            }

            for ($i = 0; $i < $fullColumns; $i++) {
                $depth = $row->depth - $i;
                foreach (range(1, $row->height) as $level) {
                    $make(['status' => Container::STORED, 'storage_row_id' => $row->id, 'depth' => $depth, 'level' => $level]);
                }
            }

            $depth = $row->depth - $fullColumns;
            for ($level = 1; $level <= $front && $depth >= 1; $level++) {
                $make(['status' => Container::STORED, 'storage_row_id' => $row->id, 'depth' => $depth, 'level' => $level]);
            }
        }

        foreach (range(1, 40) as $i) {
            $make(['status' => Container::DELIVERED]);
        }

        foreach (range(1, 3) as $i) {
            $make(['status' => Container::PENDING, 'customer_id' => null]);
        }
    }
}
