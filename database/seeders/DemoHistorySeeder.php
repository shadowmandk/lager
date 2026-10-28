<?php

namespace Database\Seeders;

use App\Models\Activity;
use App\Models\Container;
use App\Models\Operator;
use App\Models\StorageRow;
use App\Services\LocationLabel;
use App\Support\Slot;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * Fiktiv loghistorik for de seneste 10 uger, så statistik og forecast har noget at vise i en demo.
 * Posterne ændrer ikke containernes placering; de er kun tal i loggen. Køres kun sammen med DemoSeeder.
 */
class DemoHistorySeeder extends Seeder
{
    public function run(): void
    {
        if (Activity::query()->exists()) {
            return;
        }

        mt_srand(42);

        $operators = Operator::query()->pluck('id')->all();
        $containers = Container::query()->pluck('id')->all();
        $rows = StorageRow::query()->with('zone.warehouse')->get();
        $lager1 = $rows->filter(fn ($r) => $r->zone->warehouse->name === 'Lager 1')->values();
        $lager2 = $rows->filter(fn ($r) => $r->zone->warehouse->name === 'Lager 2')->values();

        if ($operators === [] || $containers === [] || $lager1->isEmpty() || $lager2->isEmpty()) {
            return;
        }

        $slotIn = function ($rows) {
            $row = $rows[mt_rand(0, $rows->count() - 1)];

            return new Slot($row->id, mt_rand(1, $row->depth), mt_rand(1, $row->height));
        };

        $start = CarbonImmutable::now()->startOfWeek()->subWeeks(10);
        $end = CarbonImmutable::now()->startOfDay();
        $entries = [];

        for ($day = $start; $day < $end; $day = $day->addDay()) {
            $weekday = $day->isoWeekday();
            $count = match (true) {
                $weekday === 7 => 0,
                $weekday === 6 => mt_rand(0, 2),
                $weekday === 1 => mt_rand(8, 14),
                default => mt_rand(4, 10),
            };

            for ($i = 0; $i < $count; $i++) {
                $roll = mt_rand(1, 100);
                $home = mt_rand(1, 100) <= 70 ? $lager1 : $lager2;
                [$action, $from, $to, $fromStatus, $toStatus] = match (true) {
                    $roll <= 55 => [Activity::MOVED, $slotIn($home), $slotIn($home), Container::STORED, Container::STORED],
                    $roll <= 72 => [Activity::DELIVERED, $slotIn($home), null, Container::STORED, Container::DELIVERED],
                    $roll <= 88 => [Activity::RETURNED, null, $slotIn($home), Container::DELIVERED, Container::STORED],
                    $roll <= 95 => [Activity::PLACED, null, $slotIn($home), Container::PENDING, Container::STORED],
                    default => [Activity::TRANSFERRED, $slotIn($lager1), $slotIn($lager2), Container::STORED, Container::TRANSIT],
                };

                $at = $day->setTime(mt_rand(6, 15), mt_rand(0, 59), mt_rand(0, 59));

                $entries[] = [
                    'operator_id' => $operators[mt_rand(0, count($operators) - 1)],
                    'action' => $action,
                    'container_id' => $containers[mt_rand(0, count($containers) - 1)],
                    'from_status' => $fromStatus,
                    'from_row_id' => $from?->rowId,
                    'from_depth' => $from?->depth,
                    'from_level' => $from?->level,
                    'from_label' => LocationLabel::for($fromStatus, $from),
                    'to_status' => $toStatus,
                    'to_row_id' => $to?->rowId,
                    'to_depth' => $to?->depth,
                    'to_level' => $to?->level,
                    'to_label' => LocationLabel::for($toStatus, $to),
                    'created_at' => $at,
                ];
            }
        }

        usort($entries, fn ($a, $b) => $a['created_at'] <=> $b['created_at']);

        foreach (array_chunk($entries, 200) as $chunk) {
            Activity::query()->insert(array_map(fn ($e) => ['created_at' => $e['created_at']->toDateTimeString()] + $e, $chunk));
        }
    }
}
