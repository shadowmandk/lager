<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\Container;
use App\Models\Warehouse;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Tal til statistiksiden. Ændrer intet.
 *
 * Definitioner:
 *  - Flytning: et træk med trucken, dvs. placeret, flyttet, udleveret, hjemkommet eller sendt til andet lager.
 *  - Ind: en container sættes på lager udefra (første placering eller hjemkomst fra kunde).
 *  - Ud: en container udleveres til kunde.
 * Handlinger, der er fortrudt, og selve fortrydelserne tæller ikke med.
 *
 * Forecast bygger på gennemsnittet af de seneste op til 8 hele uger og kræver mindst 2 ugers historik.
 */
final class StatisticsService
{
    public const MOVE_ACTIONS = [Activity::PLACED, Activity::MOVED, Activity::DELIVERED, Activity::RETURNED, Activity::TRANSFERRED];

    public const IN_ACTIONS = [Activity::PLACED, Activity::RETURNED];

    public const OUT_ACTIONS = [Activity::DELIVERED];

    public const FORECAST_WEEKS = 8;

    public const MIN_WEEKS = 2;

    private ?array $rowWarehouse = null;

    private function now(): CarbonImmutable
    {
        return CarbonImmutable::now();
    }

    /**
     * Pladser pr. lager lige nu. Containere i transit tæller som optaget på det lager, de er på vej til.
     *
     * @return array{warehouses: list<array<string, mixed>>, total: array<string, int>, pending: int, delivered: int, transit: int}
     */
    public function capacity(): array
    {
        $warehouses = Warehouse::query()->with('zones.rows')->orderBy('sort')->orderBy('id')->get();

        $used = Container::query()
            ->whereIn('status', Container::ON_SLOT)
            ->selectRaw('storage_row_id, status, count(*) as n')
            ->groupBy('storage_row_id', 'status')
            ->get();

        $available = Container::query()->available()
            ->selectRaw('storage_row_id, count(*) as n')->groupBy('storage_row_id')->pluck('n', 'storage_row_id');

        $list = [];
        $total = ['capacity' => 0, 'used' => 0, 'free' => 0, 'available' => 0];

        foreach ($warehouses as $w) {
            $row = ['name' => $w->name, 'rows_capacity' => 0, 'rows_used' => 0, 'buffer_capacity' => 0, 'buffer_used' => 0, 'transit' => 0, 'available' => 0];

            foreach ($w->zones as $zone) {
                foreach ($zone->rows as $r) {
                    $inRow = $used->where('storage_row_id', $r->id);
                    $n = (int) $inRow->sum('n');
                    $row[$zone->is_buffer ? 'buffer_capacity' : 'rows_capacity'] += $r->capacity();
                    $row[$zone->is_buffer ? 'buffer_used' : 'rows_used'] += $n;
                    $row['transit'] += (int) $inRow->where('status', Container::TRANSIT)->sum('n');
                    $row['available'] += (int) ($available[$r->id] ?? 0);
                }
            }

            $row['capacity'] = $row['rows_capacity'] + $row['buffer_capacity'];
            $row['used'] = $row['rows_used'] + $row['buffer_used'];
            $row['free'] = $row['capacity'] - $row['used'];
            $row['percent'] = $row['capacity'] > 0 ? (int) round($row['used'] / $row['capacity'] * 100) : 0;

            foreach (['capacity', 'used', 'free', 'available'] as $k) {
                $total[$k] += $row[$k];
            }

            $list[] = $row;
        }

        $total['percent'] = $total['capacity'] > 0 ? (int) round($total['used'] / $total['capacity'] * 100) : 0;

        $counts = Container::query()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');

        return [
            'warehouses' => $list,
            'total' => $total,
            'pending' => (int) ($counts[Container::PENDING] ?? 0),
            'delivered' => (int) ($counts[Container::DELIVERED] ?? 0),
            'transit' => (int) ($counts[Container::TRANSIT] ?? 0),
        ];
    }

    /**
     * Nøgletal for de seneste $days dage, inklusive i dag.
     *
     * @return array{moves: int, in: int, out: int, created: int, transfers: int, perDay: float, byOperator: array<string, int>}
     */
    public function summary(int $days): array
    {
        $from = $this->now()->startOfDay()->subDays($days - 1);
        $acts = $this->effective($from, $this->now());

        $moves = $acts->whereIn('action', self::MOVE_ACTIONS);

        return [
            'moves' => $moves->count(),
            'in' => $acts->whereIn('action', self::IN_ACTIONS)->count(),
            'out' => $acts->whereIn('action', self::OUT_ACTIONS)->count(),
            'created' => $acts->where('action', Activity::CREATED)->count(),
            'transfers' => $acts->where('action', Activity::TRANSFERRED)->count(),
            'perDay' => round($moves->count() / $days, 1),
            'byOperator' => $moves->groupBy(fn (Activity $a) => $a->actorName())
                ->map->count()->sortDesc()->all(),
        ];
    }

    /**
     * Ind, ud og flytninger pr. uge, de seneste $weeks uger inklusive den igangværende.
     *
     * @return list<array{label: string, start: CarbonImmutable, in: int, out: int, moves: int, current: bool}>
     */
    public function weekly(int $weeks = 12): array
    {
        $thisWeek = $this->now()->startOfWeek();
        $acts = $this->effective($thisWeek->subWeeks($weeks - 1), $this->now());
        $result = [];

        for ($i = $weeks - 1; $i >= 0; $i--) {
            $start = $thisWeek->subWeeks($i);
            $end = $start->addWeek();
            $inWeek = $acts->filter(fn (Activity $a) => $a->created_at >= $start && $a->created_at < $end);

            $result[] = [
                'label' => 'Uge '.$start->isoWeek(),
                'start' => $start,
                'in' => $inWeek->whereIn('action', self::IN_ACTIONS)->count(),
                'out' => $inWeek->whereIn('action', self::OUT_ACTIONS)->count(),
                'moves' => $inWeek->whereIn('action', self::MOVE_ACTIONS)->count(),
                'current' => $i === 0,
            ];
        }

        return $result;
    }

    /**
     * Gennemsnitligt antal flytninger pr. ugedag over de seneste hele uger.
     *
     * @return list<array{day: string, avg: float}>
     */
    public function weekdays(int $weeks = self::FORECAST_WEEKS): array
    {
        $end = $this->now()->startOfWeek();
        $start = $end->subWeeks($weeks);
        $moves = $this->effective($start, $end)->whereIn('action', self::MOVE_ACTIONS);
        $names = ['Man', 'Tir', 'Ons', 'Tor', 'Fre', 'Lør', 'Søn'];

        return array_map(fn (int $i) => [
            'day' => $names[$i - 1],
            'avg' => round($moves->filter(fn (Activity $a) => $a->created_at->isoWeekday() === $i)->count() / $weeks, 1),
        ], range(1, 7));
    }

    /**
     * Forecast ud fra de seneste hele uger.
     *
     * @return array<string, mixed>
     */
    public function forecast(): array
    {
        $first = Activity::query()->min('created_at');
        $historyWeeks = $first ? CarbonImmutable::parse($first)->diffInDays($this->now()) / 7 : 0;
        $weeks = (int) min(self::FORECAST_WEEKS, floor($historyWeeks));

        $base = ['enough' => $weeks >= self::MIN_WEEKS, 'weeks' => $weeks, 'historyDays' => (int) floor($historyWeeks * 7)];

        if (! $base['enough']) {
            return $base;
        }

        $end = $this->now()->startOfWeek();
        $start = $end->subWeeks($weeks);
        $acts = $this->effective($start, $end);

        $avgIn = $acts->whereIn('action', self::IN_ACTIONS)->count() / $weeks;
        $avgOut = $acts->whereIn('action', self::OUT_ACTIONS)->count() / $weeks;

        $next = [];
        for ($i = 0; $i < 4; $i++) {
            $weekStart = $end->addWeeks($i);
            $next[] = ['label' => 'Uge '.$weekStart->isoWeek(), 'in' => round($avgIn, 1), 'out' => round($avgOut, 1)];
        }

        $net = $this->netPerWarehouse($start, $end);
        $capacity = collect($this->capacity()['warehouses'])->keyBy('name');

        $warehouses = Warehouse::query()->orderBy('sort')->orderBy('id')->get()->map(function (Warehouse $w) use ($net, $capacity, $weeks) {
            $perWeek = ($net[$w->id] ?? 0) / $weeks;
            $free = $capacity[$w->name]['free'] ?? 0;
            $weeksToFull = $perWeek > 0.05 ? $free / $perWeek : null;

            return [
                'name' => $w->name,
                'free' => $free,
                'netPerWeek' => round($perWeek, 1),
                'weeksToFull' => $weeksToFull === null ? null : round($weeksToFull, 1),
                'fullDate' => $weeksToFull === null ? null : $this->now()->addDays((int) round($weeksToFull * 7)),
            ];
        })->all();

        return $base + [
            'avgIn' => round($avgIn, 1),
            'avgOut' => round($avgOut, 1),
            'next' => $next,
            'warehouses' => $warehouses,
        ];
    }

    /**
     * Handlinger i perioden, der står ved magt: hverken fortrudt eller selv en fortrydelse.
     *
     * @return Collection<int, Activity>
     */
    private function effective(CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return Activity::query()
            ->with('operator', 'user')
            ->where('created_at', '>=', $from)
            ->where('created_at', '<', $to->addSecond())
            ->whereNull('undone_by_id')
            ->where('action', '!=', Activity::UNDONE)
            ->get();
    }

    /**
     * Nettotilgang af optagne pladser pr. lager i perioden. Fortrudte handlinger og deres
     * fortrydelser går lige op, så her tælles alle poster med.
     *
     * @return array<int, int> lager-id => nettotilgang
     */
    private function netPerWarehouse(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $map = $this->rowWarehouse ??= \App\Models\StorageRow::query()
            ->join('zones', 'zones.id', '=', 'storage_rows.zone_id')
            ->pluck('zones.warehouse_id', 'storage_rows.id')
            ->all();

        $net = [];

        Activity::query()
            ->where('created_at', '>=', $from)
            ->where('created_at', '<', $to)
            ->where(fn ($q) => $q->whereNotNull('from_row_id')->orWhereNotNull('to_row_id'))
            ->get(['from_row_id', 'to_row_id'])
            ->each(function (Activity $a) use ($map, &$net) {
                $fromW = $a->from_row_id ? ($map[$a->from_row_id] ?? null) : null;
                $toW = $a->to_row_id ? ($map[$a->to_row_id] ?? null) : null;

                if ($fromW === $toW) {
                    return;
                }

                if ($toW !== null) {
                    $net[$toW] = ($net[$toW] ?? 0) + 1;
                }

                if ($fromW !== null) {
                    $net[$fromW] = ($net[$fromW] ?? 0) - 1;
                }
            });

        return $net;
    }
}
