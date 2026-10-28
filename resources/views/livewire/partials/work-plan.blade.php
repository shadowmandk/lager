@php
    /** @var array $work */
    $plan = $work['plan'];
    $target = $work['target'];
    $steps = $plan['steps'];
    $costText = fn (int $cost) => $cost === 0 ? 'Spærrer ikke for nogen' : 'Spærrer for '.$cost;
    $minutes = $plan['minutes'];
    $time = $minutes >= 60 ? intdiv($minutes, 60).' t '.($minutes % 60).' min' : $minutes.' min';
@endphp

<div class="fixed inset-0 z-40 flex justify-end bg-slate-900/40" wire:key="work-plan" wire:click.self="closePlan">
    <div class="flex h-full w-full max-w-lg flex-col bg-white shadow-2xl" role="dialog" aria-modal="true" aria-labelledby="plan-title">
        <div class="flex items-start justify-between gap-3 border-t-8 border-violet-600 p-4">
            <div>
                <div class="text-sm font-semibold uppercase tracking-wide text-violet-700">Arbejdsplan</div>
                <h2 id="plan-title" class="text-2xl font-bold">Kom ind til {{ $target->number }}</h2>
                <p class="text-slate-700">{{ $target->customer?->name ?? 'Ingen kunde' }} · står på {{ $work['targetLabel'] }}</p>
            </div>
            <button type="button" wire:click="closePlan" class="flex h-12 w-12 shrink-0 items-center justify-center rounded-lg bg-slate-100 text-slate-600" aria-label="Luk">
                <svg viewBox="0 0 24 24" class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M6 6l12 12M18 6L6 18"/></svg>
            </button>
        </div>

        <div class="flex-1 space-y-3 overflow-y-auto px-4 pb-4">
            <div class="grid grid-cols-3 gap-2 text-center">
                <div class="rounded-xl bg-slate-50 p-2">
                    <div class="text-2xl font-bold tabular-nums">{{ count($steps) }}</div>
                    <div class="text-sm text-slate-600">træk</div>
                </div>
                <div class="rounded-xl bg-slate-50 p-2">
                    <div class="text-2xl font-bold tabular-nums">{{ $time }}</div>
                    <div class="text-sm text-slate-600">cirka</div>
                </div>
                <div class="rounded-xl bg-slate-50 p-2">
                    <div class="text-2xl font-bold tabular-nums">{{ $plan['buffer'] }}</div>
                    <div class="text-sm text-slate-600">i bufferen</div>
                </div>
            </div>

            @if ($work['started'] && $work['done'] > 0)
                <p class="text-slate-700">{{ $work['done'] }} af {{ $work['total'] }} træk er udført.</p>
            @endif

            @if ($plan['problem'])
                <p class="flex items-start gap-2 rounded-lg bg-red-50 px-4 py-3 text-red-800 ring-1 ring-red-200">
                    <svg viewBox="0 0 24 24" class="mt-0.5 h-5 w-5 shrink-0" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 3l10 18H2L12 3z"/><path d="M12 10v5M12 18v.5"/></svg>
                    {{ $plan['problem'] }}
                </p>
            @else
                <p class="text-sm text-slate-600">
                    Pladserne er valgt, så containerne kommer til at stå i vejen for færrest mulige andre.
                    Tryk “Skift plads” for at vælge en anden plads. De følgende trin planlægges så om.
                </p>
            @endif

            <ol class="space-y-2">
                @foreach ($steps as $i => $step)
                    @php $sc = $step['container']; $editing = $planEdit === $sc->id; @endphp
                    <li wire:key="step-{{ $sc->id }}" @class([
                        'rounded-xl p-3 ring-1',
                        'bg-violet-50 ring-violet-300' => $work['started'] && $i === 0,
                        'bg-white ring-slate-200' => ! ($work['started'] && $i === 0),
                    ])>
                        <div class="flex items-start gap-3">
                            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-violet-600 font-bold text-white">{{ $work['done'] + $i + 1 }}</span>
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-baseline gap-x-2">
                                    <span class="text-lg font-bold">{{ $sc->number }}</span>
                                    <span class="truncate text-sm text-slate-600">{{ $sc->customer?->name ?? 'Ingen kunde' }}</span>
                                </div>
                                <dl class="mt-1 grid grid-cols-[2.5rem_1fr] gap-y-0.5 text-lg">
                                    <dt class="text-slate-500">Fra</dt><dd class="font-medium">{{ $step['fromLabel'] }}</dd>
                                    <dt class="text-slate-500">Til</dt><dd class="font-semibold text-violet-800">{{ $step['label'] }}</dd>
                                </dl>
                                <div class="mt-1 flex flex-wrap items-center gap-1.5 text-sm">
                                    <span @class(['font-medium', 'text-emerald-700' => $step['cost'] === 0, 'text-amber-800' => $step['cost'] > 0])>{{ $costText($step['cost']) }}</span>
                                    @if ($step['buffer'])
                                        <span class="rounded bg-sky-100 px-1.5 py-0.5 font-semibold text-sky-800">Buffer</span>
                                    @endif
                                    @if ($step['chosen'])
                                        <span class="rounded bg-violet-100 px-1.5 py-0.5 font-semibold text-violet-800">Dit valg</span>
                                    @endif
                                </div>
                            </div>
                            <button type="button" wire:click="editStep({{ $sc->id }})"
                                    class="btn-secondary h-11 shrink-0 px-3 text-sm" aria-expanded="{{ $editing ? 'true' : 'false' }}">
                                {{ $editing ? 'Luk' : 'Skift plads' }}
                            </button>
                        </div>

                        @if ($editing)
                            <div class="mt-3 space-y-1.5 border-t border-slate-200 pt-3">
                                <div class="text-sm font-semibold text-slate-600">Vælg en anden plads</div>
                                @foreach ($step['options'] as $opt)
                                    @php $current = $opt['key'] === $step['to']?->key(); @endphp
                                    <button type="button" wire:key="opt-{{ $sc->id }}-{{ $opt['key'] }}"
                                            @if (! $current) wire:click="chooseStep({{ $sc->id }}, @js($opt['key']))" @endif
                                            @class([
                                                'flex min-h-12 w-full items-center justify-between gap-2 rounded-lg px-3 text-left ring-1',
                                                'bg-violet-100 ring-violet-400' => $current,
                                                'bg-white ring-slate-200 active:bg-slate-50' => ! $current,
                                            ])>
                                        <span class="text-lg font-semibold">{{ $opt['label'] }}</span>
                                        <span class="flex items-center gap-1.5 text-sm">
                                            @if ($opt['buffer'])
                                                <span class="rounded bg-sky-100 px-1.5 py-0.5 font-semibold text-sky-800">Buffer</span>
                                            @endif
                                            <span @class(['text-emerald-700' => $opt['cost'] === 0, 'text-amber-800' => $opt['cost'] > 0])>{{ $costText($opt['cost']) }}</span>
                                            @if ($current) <span class="font-semibold text-violet-800">Valgt</span> @endif
                                        </span>
                                    </button>
                                @endforeach
                                @if ($step['chosen'])
                                    <button type="button" wire:click="resetStep({{ $sc->id }})" class="btn-secondary mt-1 h-11 w-full">Brug forslaget</button>
                                @endif
                            </div>
                        @endif
                    </li>
                @endforeach
            </ol>

            @if ($plan['problem'] === null)
                <p class="rounded-xl bg-emerald-50 px-4 py-3 text-emerald-900">
                    Derefter kan <strong>{{ $target->number }}</strong> tages. Den åbner med knapperne Udlevér, Flyt og Flyt til andet lager.
                </p>
            @endif
        </div>

        <div class="grid grid-cols-2 gap-3 border-t border-slate-200 p-4">
            @if ($work['started'])
                <button type="button" wire:click="stopPlan" class="btn-danger h-14 text-lg">Stop planen</button>
                <button type="button" wire:click="closePlan" class="btn-primary h-14 text-lg">Luk</button>
            @else
                <button type="button" wire:click="closePlan" class="btn-secondary h-14 text-lg">Annullér</button>
                <button type="button" wire:click="startPlan" wire:loading.attr="disabled" @disabled($plan['problem'] !== null)
                        class="btn-primary h-14 text-lg disabled:opacity-50">Start planen</button>
            @endif
        </div>
    </div>
</div>
