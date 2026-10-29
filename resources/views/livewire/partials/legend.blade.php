@php
    $p = \App\Support\CustomerPalette::COLORS;
    $free = \App\Support\CustomerPalette::AVAILABLE;
    // Lille container-symbol med valgfri kant; farverne matcher .take-ring, .slot.is-target osv. i app.css.
    $box = fn (string $fill, string $stroke, float $width = 1, string $dash = 'none') =>
        '<svg viewBox="0 0 30 18" class="h-[18px] w-[30px] shrink-0" aria-hidden="true">'
        .'<rect x="2" y="2" width="26" height="14" rx="2" fill="'.$fill.'" stroke="'.$stroke.'" stroke-width="'.$width.'" stroke-dasharray="'.$dash.'"/></svg>';
@endphp
<section class="rounded-2xl bg-white px-3 py-2 text-sm text-slate-700 shadow-sm ring-1 ring-slate-200" aria-label="Forklaring">
    <h2 class="mb-1 text-xs font-semibold uppercase tracking-wide text-slate-500">Forklaring</h2>
    <ul class="flex flex-wrap gap-x-5 gap-y-1.5">
        <li class="flex items-center gap-1.5">{!! $box($p[0]['side'], '#16a34a', 3) !!} Kan tages nu</li>
        <li class="flex items-center gap-1.5">{!! $box('rgba(34,197,94,.3)', '#16a34a', 2) !!} Kan sættes her</li>
        <li class="flex items-center gap-1.5">{!! $box($p[0]['side'], '#f59e0b', 3) !!} Passer på søgningen</li>
        <li class="flex items-center gap-1.5">{!! $box('rgba(91,155,213,.35)', '#0284c7', 2, '4 2') !!} PÅ VEJ fra andet lager</li>
        <li class="flex items-center gap-1.5">{!! $box($p[0]['side'], '#7c3aed', 3, '5 2') !!} Næste trin i arbejdsplanen</li>
        <li class="flex items-center gap-1.5">
            <svg viewBox="0 0 30 18" class="h-[18px] w-[30px] shrink-0" aria-hidden="true">
                <rect x="2" y="2" width="26" height="14" rx="2" fill="{{ $free['side'] }}" stroke="{{ $free['stroke'] }}" stroke-width="1"/>
                <text x="15" y="12.5" text-anchor="middle" font-size="8" font-weight="700" fill="{{ $free['text'] }}">LEDIG</text>
            </svg>
            Ledig til udlejning
        </li>
        <li class="flex items-center gap-1.5">
            <span class="flex shrink-0 gap-0.5" aria-hidden="true">
                @foreach ([1, 2, 4] as $i)
                    <span class="h-[14px] w-[9px] rounded-sm" style="background: {{ $p[$i]['side'] }}; box-shadow: inset 0 0 0 1px {{ $p[$i]['stroke'] }}"></span>
                @endforeach
            </span>
            Farven er kunden. Navnet står på containeren.
        </li>
    </ul>
    <p class="mt-1.5 text-slate-500">Hold fingeren på en container med grøn kant et øjeblik, og træk den til en grøn plads. Et kort tryk viser detaljer.</p>
</section>
