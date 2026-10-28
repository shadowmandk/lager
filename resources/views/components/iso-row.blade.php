@props([
    'row',
    'grid',
    'matches' => null,
    'pickTargets' => [],
    'picked' => null,
])

@php
    // Geometri i SVG-enheder. Forsiden er containerens lange side; toppen og enden ses skråt bagud til højre.
    $W = 84; $H = 44; $DX = 12; $DY = 9; $GAP = 6;
    $STEP = $W + $DX + $GAP;
    $ML = $row->depth > 1 ? 58 : 6;
    $MT = $DY + 4;
    $vbW = $ML + $row->depth * $STEP - $GAP + 2;
    $vbH = $MT + $row->height * $H + 20;
    $floor = $MT + $row->height * $H;
    $take = $grid->takeable();
    $pickedFrom = $picked?->slot()?->key();

    $ribs = function (float $x, float $y) use ($W, $H) {
        $d = '';
        for ($rx = $x + 6; $rx < $x + $W - 4; $rx += 5.5) {
            $d .= 'M'.round($rx, 1).' '.($y + 3).'v'.($H - 6);
        }
        return $d;
    };
@endphp

<svg viewBox="0 0 {{ $vbW }} {{ $vbH }}" class="iso-row h-auto w-full select-none" style="max-width: {{ round($vbW * 1.35) }}px"
     role="img" aria-label="Række {{ $row->name }}: {{ $grid->count() }} af {{ $row->capacity() }} pladser optaget">

    @if ($row->depth > 1)
        <g class="entrance" aria-hidden="true">
            <path d="M6 {{ $floor - 18 }}h34m-10 -9l10 9-10 9" fill="none" stroke="#475569" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
            <text x="23" y="{{ $floor - 34 }}" text-anchor="middle" class="iso-small">Indgang</text>
        </g>
    @endif

    <line x1="{{ $ML - 4 }}" y1="{{ $floor + 1 }}" x2="{{ $vbW - 2 }}" y2="{{ $floor + 1 }}" stroke="#94a3b8" stroke-width="2"/>

    @if ($row->depth > 1)
        @for ($d = 1; $d <= $row->depth; $d++)
            <text x="{{ $ML + ($d - 1) * $STEP + $W / 2 }}" y="{{ $floor + 16 }}" text-anchor="middle" class="iso-small">{{ $d }}</text>
        @endfor
    @endif

    @for ($d = 1; $d <= $row->depth; $d++)
        @for ($l = 1; $l <= $row->height; $l++)
            @php
                $x = $ML + ($d - 1) * $STEP;
                $y = $MT + ($row->height - $l) * $H;
                $c = $grid->at($d, $l);
                $key = "{$row->id}:{$d}:{$l}";
            @endphp

            @if ($c)
                @php
                    $col = $c->color();
                    $isTake = $take?->id === $c->id;
                    $isMatch = $matches !== null && isset($matches[$c->id]);
                    $isDim = $matches !== null && ! $isMatch;
                    $isPicked = $picked?->id === $c->id;
                    $customer = $c->customer ? \Illuminate\Support\Str::limit($c->customer->name, 13, '…') : 'Ingen kunde';
                @endphp
                <g @class(['ctr', 'ctr-take' => $isTake, 'ctr-dim' => $isDim, 'ctr-picked' => $isPicked])
                   data-container-id="{{ $c->id }}"
                   @if ($isTake)
                       data-drag-id="{{ $c->id }}" data-drag-kind="stored" data-from="{{ $key }}"
                       data-drag-number="{{ $c->number }}" data-drag-label="{{ $customer }}" data-drag-color="{{ $col['side'] }}"
                   @endif
                   wire:click="openPanel({{ $c->id }})">
                    <title>{{ $c->number }} · {{ $c->name }} · {{ $c->customer?->name ?? 'Ingen kunde' }}</title>
                    {{-- top --}}
                    <polygon points="{{ $x }},{{ $y }} {{ $x + $DX }},{{ $y - $DY }} {{ $x + $W + $DX }},{{ $y - $DY }} {{ $x + $W }},{{ $y }}"
                             fill="{{ $col['top'] }}" stroke="{{ $col['stroke'] }}" stroke-width="0.8"/>
                    {{-- dørende med låsestænger --}}
                    <polygon points="{{ $x + $W }},{{ $y }} {{ $x + $W + $DX }},{{ $y - $DY }} {{ $x + $W + $DX }},{{ $y + $H - $DY }} {{ $x + $W }},{{ $y + $H }}"
                             fill="{{ $col['end'] }}" stroke="{{ $col['stroke'] }}" stroke-width="0.8"/>
                    <path d="M{{ $x + $W + 4 }} {{ $y - 1 }}v{{ $H - 5 }}M{{ $x + $W + 8 }} {{ $y - 4 }}v{{ $H - 5 }}"
                          stroke="{{ $col['stroke'] }}" stroke-width="1" opacity="0.7"/>
                    {{-- lang side med riller --}}
                    <rect x="{{ $x }}" y="{{ $y }}" width="{{ $W }}" height="{{ $H }}" fill="{{ $col['side'] }}" stroke="{{ $col['stroke'] }}" stroke-width="0.8"/>
                    <path d="{{ $ribs($x, $y) }}" stroke="{{ $col['stroke'] }}" stroke-width="1.1" opacity="0.28"/>
                    <path d="M{{ $x }} {{ $y + 2 }}h{{ $W }}M{{ $x }} {{ $y + $H - 2 }}h{{ $W }}" stroke="{{ $col['stroke'] }}" stroke-width="1.6" opacity="0.55"/>
                    {{-- hjørnebeslag --}}
                    <g fill="{{ $col['stroke'] }}">
                        <rect x="{{ $x }}" y="{{ $y }}" width="5" height="4"/>
                        <rect x="{{ $x + $W - 5 }}" y="{{ $y }}" width="5" height="4"/>
                        <rect x="{{ $x }}" y="{{ $y + $H - 4 }}" width="5" height="4"/>
                        <rect x="{{ $x + $W - 5 }}" y="{{ $y + $H - 4 }}" width="5" height="4"/>
                    </g>
                    {{-- læseplade --}}
                    <rect x="{{ $x + 7 }}" y="{{ $y + 7 }}" width="{{ $W - 14 }}" height="{{ $H - 14 }}" rx="3" fill="#ffffff" opacity="0.88"/>
                    <text x="{{ $x + $W / 2 }}" y="{{ $y + 21 }}" text-anchor="middle" class="iso-num" fill="{{ $col['text'] }}">{{ $c->number }}</text>
                    <text x="{{ $x + $W / 2 }}" y="{{ $y + 32 }}" text-anchor="middle" class="iso-cust" fill="{{ $col['text'] }}">{{ $customer }}</text>

                    @if ($isMatch)
                        <rect x="{{ $x - 2 }}" y="{{ $y - 2 }}" width="{{ $W + 4 }}" height="{{ $H + 4 }}" rx="3" class="match-ring"/>
                    @endif
                    @if ($isTake)
                        <rect x="{{ $x + 1.5 }}" y="{{ $y + 1.5 }}" width="{{ $W - 3 }}" height="{{ $H - 3 }}" rx="2" class="take-ring"/>
                    @endif
                </g>
            @else
                @php $isPickTarget = isset($pickTargets[$key]); @endphp
                <rect @class(['slot', 'is-target' => $isPickTarget])
                      data-slot="{{ $key }}"
                      x="{{ $x + 1 }}" y="{{ $y + 1 }}" width="{{ $W - 2 }}" height="{{ $H - 2 }}" rx="4"
                      pointer-events="all"
                      @if ($isPickTarget) wire:click="prepare({{ $picked->id }}, @js($pickedFrom), '{{ $key }}')" @endif>
                    <title>Ledig plads · dybde {{ $d }}, højde {{ $l }}</title>
                </rect>
            @endif
        @endfor
    @endfor
</svg>
