@php
    $fmt = fn ($n, $dec = 0) => number_format((float) $n, $dec, ',', '.');
    $IN = 'var(--series-in)';
    $OUT = 'var(--series-out)';

    // Pæn øvre grænse for en y-akse: 1, 2, 5, 10, 20, 50 ...
    $niceMax = function (float $max): int {
        if ($max <= 4) return 4;
        $pow = 10 ** floor(log10($max));
        foreach ([1, 2, 2.5, 5, 10] as $m) {
            if ($m * $pow >= $max) return (int) ceil($m * $pow);
        }
        return (int) ceil($max);
    };
@endphp

<div class="stats-root">
    <x-topbar active="statistics"/>

    <div class="mx-auto max-w-6xl space-y-3 p-3">
        {{-- Periode --}}
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-2xl font-semibold">Statistik</h1>
            <div class="flex gap-1 rounded-lg bg-slate-200 p-1" role="group" aria-label="Periode">
                @foreach ($periods as $d => $label)
                    <button type="button" wire:click="setDays({{ $d }})" @class(['tab-btn text-slate-700', 'tab-btn-active' => $days === $d])
                            aria-pressed="{{ $days === $d ? 'true' : 'false' }}">{{ $label }}</button>
                @endforeach
            </div>
        </div>

        {{-- Nøgletal --}}
        <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
            <div class="stat-tile">
                <div class="stat-label">Flytninger</div>
                <div class="stat-value">{{ $fmt($summary['moves']) }}</div>
                <div class="stat-sub">{{ $fmt($summary['perDay'], 1) }} pr. dag, seneste {{ $periods[$days] }}</div>
            </div>
            <div class="stat-tile">
                <div class="stat-label"><span class="swatch" style="background: {{ $IN }}"></span>Ind</div>
                <div class="stat-value">{{ $fmt($summary['in']) }}</div>
                <div class="stat-sub">placeret eller kommet hjem</div>
            </div>
            <div class="stat-tile">
                <div class="stat-label"><span class="swatch" style="background: {{ $OUT }}"></span>Ud</div>
                <div class="stat-value">{{ $fmt($summary['out']) }}</div>
                <div class="stat-sub">udleveret til kunde</div>
            </div>
            <div class="stat-tile">
                <div class="stat-label">Pladser optaget</div>
                <div class="stat-value">{{ $capacity['total']['percent'] }} %</div>
                <div class="stat-sub">{{ $fmt($capacity['total']['free']) }} af {{ $fmt($capacity['total']['capacity']) }} pladser er ledige · {{ $fmt($capacity['total']['available']) }} containere ledige til udlejning</div>
            </div>
        </div>

        <div class="grid gap-3 lg:grid-cols-2">
            {{-- Kapacitet --}}
            <section class="stat-card">
                <h2 class="stat-title">Kapacitet lige nu</h2>
                <div class="space-y-4">
                    @foreach ($capacity['warehouses'] as $w)
                        @php
                            $cap = max(1, $w['capacity']);
                            $standing = $w['used'] - $w['transit'];
                        @endphp
                        <div>
                            <div class="flex items-baseline justify-between gap-2">
                                <span class="text-lg font-semibold">{{ $w['name'] }}</span>
                                <span class="text-slate-700"><strong>{{ $fmt($w['used']) }}</strong> af {{ $fmt($w['capacity']) }} optaget · {{ $w['percent'] }} %</span>
                            </div>
                            <div class="cap-bar mt-1" role="img"
                                 aria-label="{{ $w['name'] }}: {{ $w['used'] }} af {{ $w['capacity'] }} pladser optaget, heraf {{ $w['transit'] }} reserveret til containere på vej">
                                @if ($standing > 0)
                                    <span class="cap-seg" style="width: {{ $standing / $cap * 100 }}%; background: {{ $IN }}" title="{{ $standing }} står på lageret"></span>
                                @endif
                                @if ($w['transit'] > 0)
                                    <span class="cap-seg cap-transit" style="width: {{ $w['transit'] / $cap * 100 }}%" title="{{ $w['transit'] }} reserveret til containere på vej"></span>
                                @endif
                            </div>
                            <div class="mt-1 text-sm text-slate-600">
                                Rækker {{ $w['rows_used'] }}/{{ $w['rows_capacity'] }} · Buffer {{ $w['buffer_used'] }}/{{ $w['buffer_capacity'] }}
                                @if ($w['transit'] > 0) · {{ $w['transit'] }} på vej hertil @endif
                                · <strong>{{ $fmt($w['free']) }} ledige pladser</strong>
                                · {{ $fmt($w['available']) }} {{ $w['available'] === 1 ? 'container' : 'containere' }} ledige til udlejning
                            </div>
                        </div>
                    @endforeach
                </div>
                <div class="mt-4 flex flex-wrap gap-x-5 gap-y-1 text-sm text-slate-600">
                    <span><span class="swatch" style="background: {{ $IN }}"></span>Står på lager</span>
                    <span><span class="swatch cap-transit"></span>Reserveret, på vej</span>
                    <span><span class="swatch bg-slate-200"></span>Ledig</span>
                </div>
                <p class="mt-3 text-sm text-slate-600">
                    Uden for lagrene: {{ $fmt($capacity['delivered']) }} udleveret til kunde og {{ $fmt($capacity['pending']) }} venter på plads.
                </p>
            </section>

            {{-- Forecast --}}
            <section class="stat-card">
                <h2 class="stat-title">Forecast</h2>
                @if (! $forecast['enough'])
                    <p class="text-slate-700">
                        Forecast kræver mindst {{ \App\Services\StatisticsService::MIN_WEEKS }} ugers historik i loggen.
                        Der er {{ $forecast['historyDays'] }} {{ $forecast['historyDays'] === 1 ? 'dag' : 'dage' }} endnu.
                    </p>
                @else
                    <div class="space-y-3">
                        @foreach ($forecast['warehouses'] as $w)
                            <div class="rounded-xl bg-slate-50 p-3">
                                <div class="text-sm font-semibold uppercase tracking-wide text-slate-500">{{ $w['name'] }}</div>
                                @if ($w['weeksToFull'] === null)
                                    <p class="text-lg font-semibold text-slate-800">Fyldes ikke op med det nuværende tempo</p>
                                    <p class="text-sm text-slate-600">Netto {{ $fmt($w['netPerWeek'], 1) }} containere pr. uge · {{ $w['free'] }} ledige pladser</p>
                                @else
                                    <p class="text-lg font-semibold text-slate-800">
                                        Fuldt om ca. {{ $w['weeksToFull'] < 1 ? 'under en uge' : $fmt($w['weeksToFull'], 0).' uger' }}
                                    </p>
                                    <p class="text-sm text-slate-600">
                                        Omkring {{ $w['fullDate']->translatedFormat('j. F Y') }} · netto +{{ $fmt($w['netPerWeek'], 1) }} pr. uge · {{ $w['free'] }} ledige pladser
                                    </p>
                                @endif
                            </div>
                        @endforeach
                    </div>

                    <h3 class="mt-4 text-sm font-semibold uppercase tracking-wide text-slate-500">Forventet ind og ud</h3>
                    <table class="stat-table mt-1">
                        <thead><tr><th>Uge</th><th class="num">Ind</th><th class="num">Ud</th></tr></thead>
                        <tbody>
                            @foreach ($forecast['next'] as $n)
                                <tr><td>{{ $n['label'] }}</td><td class="num">{{ $fmt($n['in'], 1) }}</td><td class="num">{{ $fmt($n['out'], 1) }}</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                    <p class="mt-2 text-sm text-slate-500">
                        Regnet ud fra gennemsnittet af de seneste {{ $forecast['weeks'] }} hele uger: {{ $fmt($forecast['avgIn'], 1) }} ind og {{ $fmt($forecast['avgOut'], 1) }} ud pr. uge.
                    </p>
                @endif
            </section>
        </div>

        {{-- Ind og ud pr. uge --}}
        @php
            $W = 720; $Hh = 220; $L = 34; $R = 8; $T = 12; $B = 30;
            $plotW = $W - $L - $R; $plotH = $Hh - $T - $B;
            $max = $niceMax((float) max(1, collect($weekly)->max(fn ($w) => max($w['in'], $w['out']))));
            $band = $plotW / count($weekly);
            $barW = min(18, ($band - 10) / 2);
            $y = fn ($v) => $T + $plotH - ($v / $max * $plotH);
        @endphp
        <section class="stat-card">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h2 class="stat-title">Ind og ud pr. uge</h2>
                <div class="flex gap-4 text-sm text-slate-700">
                    <span><span class="swatch" style="background: {{ $IN }}"></span>Ind</span>
                    <span><span class="swatch" style="background: {{ $OUT }}"></span>Ud</span>
                </div>
            </div>
            <svg viewBox="0 0 {{ $W }} {{ $Hh }}" class="chart" role="img" aria-label="Containere ind og ud pr. uge de seneste 12 uger">
                @foreach ([0, $max / 2, $max] as $tick)
                    <line x1="{{ $L }}" x2="{{ $W - $R }}" y1="{{ $y($tick) }}" y2="{{ $y($tick) }}" class="grid-line"/>
                    <text x="{{ $L - 6 }}" y="{{ $y($tick) + 4 }}" text-anchor="end" class="axis-text">{{ $fmt($tick) }}</text>
                @endforeach
                @foreach ($weekly as $i => $w)
                    @php $cx = $L + $band * $i + $band / 2; @endphp
                    <g class="bar-group">
                        <title>{{ $w['label'] }}{{ $w['current'] ? ' (indtil nu)' : '' }}: {{ $w['in'] }} ind, {{ $w['out'] }} ud</title>
                        <rect x="{{ $L + $band * $i }}" y="{{ $T }}" width="{{ $band }}" height="{{ $plotH }}" class="hit"/>
                        @if ($w['in'] > 0)
                            <path d="{{ \App\Support\ChartPath::bar($cx - $barW - 1, $y($w['in']), $barW, $T + $plotH - $y($w['in'])) }}" fill="{{ $IN }}" @class(['bar', 'bar-partial' => $w['current']])/>
                        @endif
                        @if ($w['out'] > 0)
                            <path d="{{ \App\Support\ChartPath::bar($cx + 1, $y($w['out']), $barW, $T + $plotH - $y($w['out'])) }}" fill="{{ $OUT }}" @class(['bar', 'bar-partial' => $w['current']])/>
                        @endif
                    </g>
                    <text x="{{ $cx }}" y="{{ $Hh - 10 }}" text-anchor="middle" class="axis-text">{{ $w['start']->isoWeek() }}</text>
                @endforeach
                <line x1="{{ $L }}" x2="{{ $W - $R }}" y1="{{ $T + $plotH }}" y2="{{ $T + $plotH }}" class="base-line"/>
            </svg>
            <p class="text-sm text-slate-500">Ugenumre. Den sidste uge er ikke slut endnu og vises lysere.</p>
            <details class="mt-2">
                <summary class="cursor-pointer text-sm font-medium text-slate-700">Vis tallene</summary>
                <table class="stat-table mt-2">
                    <thead><tr><th>Uge</th><th class="num">Ind</th><th class="num">Ud</th><th class="num">Flytninger</th></tr></thead>
                    <tbody>
                        @foreach (array_reverse($weekly) as $w)
                            <tr><td>{{ $w['label'] }}{{ $w['current'] ? ' (indtil nu)' : '' }}</td><td class="num">{{ $w['in'] }}</td><td class="num">{{ $w['out'] }}</td><td class="num">{{ $w['moves'] }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            </details>
        </section>

        <div class="grid gap-3 lg:grid-cols-2">
            {{-- Travlhed pr. ugedag --}}
            @php
                $W2 = 420; $H2 = 200; $T2 = 22; $B2 = 26; $L2 = 8; $R2 = 8;
                $plotW2 = $W2 - $L2 - $R2; $plotH2 = $H2 - $T2 - $B2;
                $max2 = max(1, collect($weekdays)->max('avg'));
                $band2 = $plotW2 / 7;
                $barW2 = min(30, $band2 - 14);
            @endphp
            <section class="stat-card">
                <h2 class="stat-title">Flytninger pr. ugedag</h2>
                <svg viewBox="0 0 {{ $W2 }} {{ $H2 }}" class="chart" role="img" aria-label="Gennemsnitligt antal flytninger pr. ugedag">
                    @foreach ($weekdays as $i => $d)
                        @php
                            $h = $d['avg'] / $max2 * $plotH2;
                            $cx = $L2 + $band2 * $i + $band2 / 2;
                        @endphp
                        <g class="bar-group">
                            <title>{{ $d['day'] }}: {{ $fmt($d['avg'], 1) }} flytninger i gennemsnit</title>
                            <rect x="{{ $L2 + $band2 * $i }}" y="0" width="{{ $band2 }}" height="{{ $H2 - $B2 }}" class="hit"/>
                            @if ($d['avg'] > 0)
                                <path d="{{ \App\Support\ChartPath::bar($cx - $barW2 / 2, $T2 + $plotH2 - $h, $barW2, $h) }}" fill="{{ $IN }}" class="bar"/>
                            @endif
                            <text x="{{ $cx }}" y="{{ $T2 + $plotH2 - $h - 6 }}" text-anchor="middle" class="value-text">{{ $fmt($d['avg'], 1) }}</text>
                        </g>
                        <text x="{{ $cx }}" y="{{ $H2 - 8 }}" text-anchor="middle" class="axis-text">{{ $d['day'] }}</text>
                    @endforeach
                    <line x1="{{ $L2 }}" x2="{{ $W2 - $R2 }}" y1="{{ $T2 + $plotH2 }}" y2="{{ $T2 + $plotH2 }}" class="base-line"/>
                </svg>
                <p class="text-sm text-slate-500">Gennemsnit over de seneste {{ \App\Services\StatisticsService::FORECAST_WEEKS }} hele uger.</p>
            </section>

            {{-- Pr. medarbejder --}}
            <section class="stat-card">
                <h2 class="stat-title">Flytninger pr. medarbejder, seneste {{ $periods[$days] }}</h2>
                @php $maxOp = max(1, collect($summary['byOperator'])->max() ?? 1); @endphp
                <div class="space-y-2">
                    @forelse ($summary['byOperator'] as $name => $n)
                        <div class="grid grid-cols-[8rem_1fr_3rem] items-center gap-2">
                            <span class="truncate font-medium">{{ $name }}</span>
                            <span class="h-3 rounded-r" style="width: {{ max(2, $n / $maxOp * 100) }}%; background: {{ $IN }}"></span>
                            <span class="text-right tabular-nums">{{ $n }}</span>
                        </div>
                    @empty
                        <p class="text-slate-600">Ingen flytninger i perioden.</p>
                    @endforelse
                </div>
                @if ($summary['transfers'] > 0 || $summary['created'] > 0)
                    <p class="mt-3 text-sm text-slate-600">
                        I perioden er {{ $summary['created'] }} containere oprettet og {{ $summary['transfers'] }} sendt mellem lagrene.
                    </p>
                @endif
            </section>
        </div>

        <p class="px-1 text-sm text-slate-500">
            En flytning er et træk med trucken: placeret, flyttet, udleveret, hjemkommet eller sendt til andet lager.
            Ind er placeret eller kommet hjem. Ud er udleveret. Fortrudte handlinger tæller ikke med.
        </p>
    </div>
</div>
