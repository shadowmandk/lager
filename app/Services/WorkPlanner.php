<?php

namespace App\Services;

use App\Models\Container;
use App\Models\Setting;
use App\Models\StorageRow;
use App\Support\Slot;
use Illuminate\Support\Collection;

/**
 * Arbejdsplan: hvor skal containerne i vejen stå, så man kan komme ind til en bestemt container?
 *
 * Containerne i vejen flyttes i den rækkefølge, de kan tages. Hver får den lovlige plads i lagerets
 * andre rækker eller buffer, der ikke spærrer en ledig container inde, som kan tages nu (traps), og
 * derefter giver færrest containere én mere i vejen. Ved lighed vælges pladsen,
 * der spærrer færrest tomme pladser inde, så en række frem for bufferen, så den laveste plads.
 * Planen regnes igennem trin for trin, så hvert trin tager højde for de forrige. Ændrer intet.
 */
final class WorkPlanner
{
    public const OPTIONS = 8;

    /**
     * @param  array<int, string>  $overrides  containerens id => pladsnøgle, valgt af medarbejderen
     * @return array{
     *     steps: list<array{container: Container, from: Slot, fromLabel: string, to: ?Slot, label: ?string, cost: int, buffer: bool, traps: ?string, chosen: bool, options: list<array{key: string, label: string, cost: int, buffer: bool, traps: ?string}>}>,
     *     blockers: int,
     *     missing: int,
     *     problem: ?string,
     *     dropped: list<int>,
     *     minutes: int,
     *     buffer: int,
     *     cost: int,
     * }|null
     */
    public function plan(Container $target, array $overrides = []): ?array
    {
        if (! $target->isStored()) {
            return null;
        }

        $source = StorageRow::query()->with('zone')->findOrFail($target->storage_row_id);
        $rows = $this->rows($source->zone->warehouse_id);

        $stored = Container::query()
            ->with('customer')
            ->whereIn('status', Container::ON_SLOT)
            ->whereIn('storage_row_id', $rows->pluck('id'))
            ->get()
            ->groupBy('storage_row_id');

        $grids = [];
        foreach ($rows as $row) {
            $grids[$row->id] = RowGrid::fromContainers($row, $stored->get($row->id, []));
        }

        $blockers = $grids[$source->id]->blockersFor($target);
        $result = [
            'steps' => [], 'blockers' => count($blockers), 'missing' => 0, 'problem' => null,
            'dropped' => [], 'minutes' => 0, 'buffer' => 0, 'cost' => 0,
        ];

        if ($inTransit = collect($blockers)->first(fn (Container $c) => $c->isInTransit())) {
            $result['problem'] = "{$inTransit->number} står i vejen og er i transit. Den skal meldes sat i buffer, før planen kan laves.";

            return $result;
        }

        foreach ($blockers as $i => $blocker) {
            $candidates = $this->candidates($rows, $grids, $source->id);

            if ($candidates === []) {
                $result['missing'] = count($blockers) - $i;
                $result['problem'] = $result['missing'] === 1
                    ? 'Der mangler en ledig plads til den sidste container. Ryd en plads i en anden række eller i bufferen.'
                    : "Der mangler ledige pladser til {$result['missing']} containere. Ryd pladser i andre rækker eller i bufferen.";
                break;
            }

            $choice = $candidates[0];
            $chosen = false;

            if (isset($overrides[$blocker->id])) {
                $override = collect($candidates)->firstWhere('key', $overrides[$blocker->id]);

                if ($override === null) {
                    $result['dropped'][] = $blocker->id;
                } else {
                    $choice = $override;
                    $chosen = true;
                }
            }

            $from = $blocker->slot();
            $to = $choice['slot'];

            $result['steps'][] = [
                'container' => $blocker,
                'from' => $from,
                'fromLabel' => $source->slotName($from->depth, $from->level),
                'to' => $to,
                'label' => $choice['label'],
                'cost' => $choice['cost'],
                'buffer' => $choice['buffer'],
                'traps' => $choice['traps'],
                'chosen' => $chosen,
                'options' => array_map(
                    fn (array $c) => ['key' => $c['key'], 'label' => $c['label'], 'cost' => $c['cost'], 'buffer' => $c['buffer'], 'traps' => $c['traps']],
                    array_slice($candidates, 0, self::OPTIONS),
                ),
            ];

            $result['buffer'] += $choice['buffer'] ? 1 : 0;
            $result['cost'] += $choice['cost'];

            $grids[$source->id] = $grids[$source->id]->without($blocker->id);
            $grids[$to->rowId] = $grids[$to->rowId]->with($blocker, $to->depth, $to->level);
        }

        $result['minutes'] = count($result['steps']) * Setting::minutesPerMove();

        return $result;
    }

    /**
     * Lovlige pladser lige nu i alle rækker undtagen den, der graves i, bedste først.
     *
     * @param  Collection<int, StorageRow>  $rows
     * @param  array<int, RowGrid>  $grids
     * @return list<array{slot: Slot, key: string, label: string, cost: int, buffer: bool, sort: array}>
     */
    private function candidates(Collection $rows, array $grids, int $sourceRowId): array
    {
        $candidates = [];

        foreach ($rows->values() as $order => $row) {
            if ($row->id === $sourceRowId) {
                continue;
            }

            $grid = $grids[$row->id];
            $buffer = (bool) $row->zone->is_buffer;

            foreach ($grid->legalTargets() as [$depth, $level]) {
                $slot = new Slot($row->id, $depth, $level);
                $cost = $grid->buriedBy($depth, $level);
                $traps = $grid->trapsAvailable($depth, $level)?->number;

                $candidates[] = [
                    'slot' => $slot,
                    'key' => $slot->key(),
                    'label' => $row->slotName($depth, $level),
                    'cost' => $cost,
                    'buffer' => $buffer,
                    'traps' => $traps,
                    'sort' => [$traps === null ? 0 : 1, $cost, $grid->blockedBehind($depth), $buffer ? 1 : 0, $level, $order, -$depth],
                ];
            }
        }

        usort($candidates, fn (array $a, array $b) => $a['sort'] <=> $b['sort']);

        return $candidates;
    }

    /** @return Collection<int, StorageRow> */
    private function rows(int $warehouseId): Collection
    {
        return StorageRow::query()
            ->with('zone')
            ->join('zones', 'zones.id', '=', 'storage_rows.zone_id')
            ->where('zones.warehouse_id', $warehouseId)
            ->orderBy('zones.sort')->orderBy('zones.id')
            ->orderBy('storage_rows.sort')->orderBy('storage_rows.id')
            ->select('storage_rows.*')
            ->get()
            ->values();
    }
}
