<div x-data="board({ pingUrl: @js(route('ping')) })"
     data-version="{{ $version }}"
     @pointerdown="down($event)"
     @click.capture="swallowClick($event)"
     @contextmenu="if ($event.target.closest('[data-drag-id]')) $event.preventDefault()">

    <span hidden data-drag-rows="{{ json_encode($dragRows) }}"></span>

    <x-topbar active="board">
        <div class="flex gap-1 rounded-lg bg-slate-700 p-1">
            @foreach ($warehouses as $w)
                <button type="button" wire:click="selectWarehouse({{ $w->id }})"
                    @class(['tab-btn', 'tab-btn-active' => $warehouse?->id === $w->id])>{{ $w->name }}</button>
            @endforeach
        </div>

        <label class="relative min-w-40 flex-1">
            <span class="sr-only">Søg</span>
            <svg viewBox="0 0 24 24" class="pointer-events-none absolute left-3 top-1/2 h-5 w-5 -translate-y-1/2 text-slate-400" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg>
            <input type="search" wire:model.live.debounce.250ms="search" placeholder="Nummer, navn eller kunde"
                   class="h-11 w-full rounded-lg border-0 bg-white pl-10 pr-10 text-base text-slate-900 placeholder:text-slate-400 focus:ring-2 focus:ring-sky-400">
            @if ($search !== '')
                <button type="button" wire:click="clearSearch" class="absolute right-1 top-1/2 flex h-9 w-9 -translate-y-1/2 items-center justify-center rounded-md text-slate-500" aria-label="Ryd søgning">
                    <svg viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M6 6l12 12M18 6L6 18"/></svg>
                </button>
            @endif
        </label>
    </x-topbar>

    {{-- Forbindelse mistet --}}
    <div x-show="!online" x-cloak class="sticky top-[60px] z-20 bg-red-600 px-4 py-2 text-center font-semibold text-white">
        Ingen forbindelse til serveren. Tavlen er måske ikke opdateret, og flytninger kan ikke bekræftes.
    </div>

    {{-- Søgeresultater --}}
    @if ($search !== '')
        <div class="border-b border-slate-200 bg-white px-3 py-2">
            @if ($results->isEmpty())
                <p class="text-slate-600">Ingen containere matcher “{{ $search }}”.</p>
            @else
                <div class="flex gap-2 overflow-x-auto pb-1">
                    @foreach ($results as $r)
                        <button type="button" wire:click="openPanel({{ $r->id }})" wire:key="res-{{ $r->id }}"
                                class="flex shrink-0 items-center gap-2 rounded-lg bg-slate-50 px-3 py-2 text-left ring-1 ring-slate-200 active:bg-slate-100">
                            <span class="h-8 w-2 rounded-sm" style="background: {{ $r->color()['side'] }}"></span>
                            <span>
                                <span class="block font-semibold">{{ $r->number }} <span class="font-normal text-slate-600">{{ $r->name }}</span></span>
                                <span class="block text-sm text-slate-600">{{ $r->customer?->name ?? 'Ingen kunde' }} · {{ \App\Services\LocationLabel::forContainer($r) }}</span>
                            </span>
                        </button>
                    @endforeach
                </div>
            @endif
        </div>
    @endif

    {{-- Valgt container venter på en plads --}}
    @if ($pickedContainer)
        <div class="sticky top-[60px] z-20 flex flex-wrap items-center justify-between gap-2 bg-sky-600 px-4 py-2 text-white">
            <span class="text-lg">
                <strong>{{ $pickedContainer->number }}</strong> er valgt. Tryk på en grøn plads{{ $pickedContainer->isStored() ? ', eller på “Udlevér til kunde”' : '' }}.
                Du kan skifte lager med fanerne.
            </span>
            <span class="flex gap-2">
                @if ($pickedContainer->isStored())
                    <button type="button" wire:click="prepare({{ $pickedContainer->id }}, @js($pickedContainer->slot()->key()), 'deliver')"
                            class="rounded-lg bg-white px-4 py-2 font-semibold text-sky-800">Udlevér til kunde</button>
                @endif
                <button type="button" wire:click="cancelPick" class="rounded-lg bg-white/20 px-4 py-2 font-semibold">Annullér</button>
            </span>
        </div>
    @endif

    <div @class(['grid gap-3 p-3 2xl:grid-cols-[minmax(0,1fr)_18rem]', 'pb-48' => $work && $work['started']])>
        <main class="space-y-3">
            @forelse ($warehouse?->zones ?? [] as $zone)
                <section class="rounded-2xl bg-white p-3 shadow-sm ring-1 ring-slate-200" wire:key="zone-{{ $zone->id }}">
                    <h2 class="mb-1 text-sm font-semibold uppercase tracking-wide text-slate-500">{{ $zone->name }}</h2>

                    @if ($zone->rows->isNotEmpty() && $zone->rows->every(fn ($r) => $r->depth === 1))
                        <div class="flex flex-wrap items-end gap-x-8 gap-y-3 pl-12">
                            @foreach ($zone->rows as $row)
                                <div wire:key="row-{{ $row->id }}" class="flex w-32 flex-col items-center">
                                    <x-iso-row :row="$row" :grid="$grids[$row->id]" :matches="$matches" :pick-targets="$pickTargets" :picked="$pickedContainer" :plan-step="$planStep"/>
                                    <span class="text-sm font-medium text-slate-600">{{ $row->name }}</span>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <div class="space-y-1">
                            @foreach ($zone->rows as $row)
                                <div wire:key="row-{{ $row->id }}" class="flex items-center gap-2">
                                    <div class="w-10 shrink-0 text-center">
                                        <div class="text-2xl font-bold text-slate-700">{{ $row->name }}</div>
                                        <div class="text-xs text-slate-500">{{ $grids[$row->id]->count() }}/{{ $row->capacity() }}</div>
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <x-iso-row :row="$row" :grid="$grids[$row->id]" :matches="$matches" :pick-targets="$pickTargets" :picked="$pickedContainer" :plan-step="$planStep"/>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </section>
            @empty
                <p class="rounded-2xl bg-white p-6 text-slate-600">Der er ikke sat nogen rækker op endnu. Det gøres under opsætning.</p>
            @endforelse

            <p class="px-1 text-sm text-slate-500">
                Grøn kant: kan tages nu. Hold fingeren på den et øjeblik, og træk den til en grøn plads. Et kort tryk viser detaljer.
            </p>
        </main>

        <aside class="grid content-start items-start gap-3 md:grid-cols-3 2xl:grid-cols-1">
            <div class="space-y-3">
            <div data-drop="deliver"
                 @if ($pickedContainer?->isStored()) wire:click="prepare({{ $pickedContainer->id }}, @js($pickedContainer->slot()->key()), 'deliver')" @endif
                 @class(['drop-deliver', 'is-target' => $pickedContainer?->isStored()])>
                <svg viewBox="0 0 24 24" class="h-7 w-7" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M3 7h11v10H3zM14 10h4l3 3v4h-7z"/><circle cx="7" cy="18" r="1.8"/><circle cx="17" cy="18" r="1.8"/></svg>
                Udlevér til kunde
            </div>

            @if ($transitList->isNotEmpty())
                <section class="rounded-2xl bg-sky-50 p-3 shadow-sm ring-1 ring-sky-200">
                    <h2 class="mb-2 text-sm font-semibold uppercase tracking-wide text-sky-800">I transit ({{ $transitList->count() }})</h2>
                    <div class="space-y-2">
                        @foreach ($transitList as $c)
                            <div wire:key="transit-{{ $c->id }}" class="overflow-hidden rounded-lg bg-white ring-1 ring-sky-200">
                                <button type="button" wire:click="openPanel({{ $c->id }})" class="block w-full px-3 py-2 text-left active:bg-slate-50">
                                    <span class="block truncate font-semibold">{{ $c->number }} <span class="font-normal text-slate-600">{{ $c->customer?->name ?? 'Ingen kunde' }}</span></span>
                                    <span class="block truncate text-sm text-sky-800">Til {{ \App\Services\LocationLabel::place($c->location()) }}</span>
                                </button>
                                <button type="button" wire:click="prepareArrive({{ $c->id }})" class="h-11 w-full border-t border-sky-100 bg-sky-600 font-semibold text-white active:bg-sky-700">
                                    Sat i buffer
                                </button>
                            </div>
                        @endforeach
                    </div>
                </section>
            @endif

            <section class="rounded-2xl bg-white p-3 shadow-sm ring-1 ring-slate-200">
                @if ($microbizzOn)
                    <button type="button" wire:click="syncMicrobizz" wire:loading.attr="disabled" wire:target="syncMicrobizz"
                            class="btn-primary mb-3 flex h-14 w-full items-center justify-center gap-2 text-lg disabled:opacity-60">
                        <svg viewBox="0 0 24 24" class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path d="M12 4v11m-5-5l5 5 5-5M5 20h14"/></svg>
                        <span wire:loading.remove wire:target="syncMicrobizz">Hent nye fra Microbizz</span>
                        <span wire:loading wire:target="syncMicrobizz">Henter …</span>
                    </button>
                @else
                    <button type="button" wire:click="openCreate" class="btn-primary mb-3 flex h-14 w-full items-center justify-center gap-2 text-lg">
                        <svg viewBox="0 0 24 24" class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="2.4" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
                        Ny container
                    </button>
                @endif
                <h2 class="mb-2 text-sm font-semibold uppercase tracking-wide text-slate-500">Venter på plads ({{ $pendingList->count() }})</h2>
                <div class="space-y-2">
                    @forelse ($pendingList as $c)
                        @include('livewire.partials.chip', ['c' => $c, 'kind' => 'pending'])
                    @empty
                        <p class="text-sm text-slate-500">Ingen nye containere.</p>
                    @endforelse
                </div>
            </section>
            </div>

            <section class="rounded-2xl bg-white p-3 shadow-sm ring-1 ring-slate-200 md:col-span-2 2xl:col-span-1">
                <h2 class="mb-2 text-sm font-semibold uppercase tracking-wide text-slate-500">
                    Udleveret til kunde ({{ $search !== '' ? $deliveredList->count().' af ' : '' }}{{ $deliveredCount }})
                </h2>
                <div class="grid max-h-[60vh] gap-2 overflow-y-auto pr-1 sm:grid-cols-2 2xl:grid-cols-1">
                    @forelse ($deliveredList as $c)
                        @include('livewire.partials.chip', ['c' => $c, 'kind' => 'delivered'])
                    @empty
                        <p class="text-sm text-slate-500">Ingen.</p>
                    @endforelse
                </div>
            </section>
        </aside>
    </div>

    {{-- Udlevér-felt, der dukker op under træk, uanset skærmstørrelse --}}
    <div x-show="dragging && dragging.kind === 'stored'" x-cloak data-drop="deliver"
         class="drop-deliver drop-deliver-floating is-target">
        <svg viewBox="0 0 24 24" class="h-7 w-7" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M3 7h11v10H3zM14 10h4l3 3v4h-7z"/><circle cx="7" cy="18" r="1.8"/><circle cx="17" cy="18" r="1.8"/></svg>
        Slip her for at udlevere til kunde
    </div>

    {{-- Besked --}}
    @if ($notice)
        <div wire:key="notice-{{ $noticeId }}" x-data="{ show: true }" x-init="setTimeout(() => show = false, 5000)" x-show="show" x-transition.opacity
             @class([
                 'fixed left-1/2 top-20 z-50 w-[min(92vw,36rem)] -translate-x-1/2 rounded-xl px-5 py-3 text-lg font-medium shadow-lg',
                 'bg-emerald-600 text-white' => $noticeType === 'ok',
                 'bg-red-600 text-white' => $noticeType === 'error',
             ])
             @click="show = false" role="status">
            {{ $notice }}
        </div>
    @endif

    {{-- Bekræftelse --}}
    @if ($confirm)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 p-4" data-confirm-open wire:key="confirm">
            <div class="w-full max-w-lg rounded-2xl bg-white p-5 shadow-xl" role="dialog" aria-modal="true" aria-labelledby="confirm-title">
                <h2 id="confirm-title" class="text-2xl font-semibold">
                    @switch($confirm['type'])
                        @case('deliver') Udlevér container? @break
                        @case('return') Container kommer hjem @break
                        @case('place') Placér ny container @break
                        @case('transfer') Flyt til {{ $confirm['warehouse'] }}? @break
                        @case('arrive') Sat i buffer? @break
                        @default Flyt container?
                    @endswitch
                </h2>

                <div class="mt-3 rounded-xl bg-slate-50 p-4">
                    <div class="text-3xl font-bold">{{ $confirm['number'] }}</div>
                    <div class="text-lg text-slate-700">{{ $confirm['name'] ?: 'Uden navn' }} · {{ $confirm['customer'] ?? 'Ingen kunde' }}</div>
                </div>

                <dl class="mt-4 grid grid-cols-[4rem_1fr] gap-y-2 text-lg">
                    <dt class="text-slate-500">Fra</dt><dd class="font-medium">{{ $confirm['fromLabel'] }}</dd>
                    <dt class="text-slate-500">Til</dt><dd class="font-semibold text-emerald-700">{{ $confirm['toLabel'] }}</dd>
                </dl>

                @if ($confirm['type'] === 'transfer')
                    <p class="mt-4 rounded-lg bg-sky-50 px-4 py-3 text-sky-900">
                        Containeren står i transit, til du trykker “Sat i buffer”. Bufferpladsen er reserveret imens, og den gamle plads bliver fri med det samme.
                    </p>
                @elseif ($confirm['type'] === 'arrive')
                    <p class="mt-4 rounded-lg bg-sky-50 px-4 py-3 text-sky-900">
                        Bekræft kun, når containeren står på pladsen.
                    </p>
                @endif

                @if ($confirm['type'] === 'return' && ! ($confirm['fromMicrobizz'] ?? false))
                    <label class="mt-4 block">
                        <span class="text-sm font-medium text-slate-600">Kunde fremover</span>
                        <select wire:model="returnCustomerId" class="mt-1 h-12 w-full rounded-lg border-slate-300 text-lg">
                            <option value="">Ingen kunde</option>
                            @foreach ($customers as $cu)
                                <option value="{{ $cu->id }}">{{ $cu->name }} ({{ $cu->number }})</option>
                            @endforeach
                        </select>
                    </label>
                @endif

                @foreach ($confirm['warnings'] as $warning)
                    <p class="mt-4 flex items-start gap-2 rounded-lg bg-amber-100 px-4 py-3 text-amber-900">
                        <svg viewBox="0 0 24 24" class="mt-0.5 h-5 w-5 shrink-0" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 3l10 18H2L12 3z"/><path d="M12 10v5M12 18v.5"/></svg>
                        {{ $warning }}
                    </p>
                @endforeach

                <div class="mt-6 grid grid-cols-2 gap-3">
                    <button type="button" wire:click="cancelConfirm" class="btn-secondary h-14 text-lg">Annullér</button>
                    <button type="button" wire:click="confirmMove" wire:loading.attr="disabled" x-bind:disabled="!online"
                            class="btn-primary h-14 text-lg disabled:opacity-50">Bekræft</button>
                </div>
            </div>
        </div>
    @endif

    {{-- Ny container --}}
    @if ($creating)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 p-4" data-confirm-open wire:key="create">
            <form wire:submit="createContainer" class="w-full max-w-lg space-y-4 rounded-2xl bg-white p-5 shadow-xl" role="dialog" aria-modal="true" aria-labelledby="create-title">
                <h2 id="create-title" class="text-2xl font-semibold">Ny container</h2>
                <p class="text-slate-600">Den kommer under “Venter på plads” og er valgt bagefter, så du kan sætte den med det samme.</p>

                <label class="block">
                    <span class="text-sm font-medium text-slate-600">Containernummer</span>
                    <input type="text" wire:model="newContainer.number" maxlength="30" autocomplete="off" autocapitalize="characters"
                           class="mt-1 h-14 w-full rounded-lg border-slate-300 text-2xl font-semibold uppercase" autofocus>
                    @error('newContainer.number') <span class="mt-1 block text-red-700">{{ $message }}</span> @enderror
                </label>

                <label class="block">
                    <span class="text-sm font-medium text-slate-600">Navn (valgfrit)</span>
                    <input type="text" wire:model="newContainer.name" maxlength="100" class="mt-1 h-12 w-full rounded-lg border-slate-300 text-lg">
                </label>

                <label class="block">
                    <span class="text-sm font-medium text-slate-600">Kunde (valgfrit)</span>
                    <select wire:model="newContainer.customer_id" class="mt-1 h-12 w-full rounded-lg border-slate-300 text-lg">
                        <option value="">Ingen kunde</option>
                        @foreach ($customers as $cu)
                            <option value="{{ $cu->id }}">{{ $cu->name }} ({{ $cu->number }})</option>
                        @endforeach
                    </select>
                </label>

                <div class="grid grid-cols-2 gap-3 pt-2">
                    <button type="button" wire:click="cancelCreate" class="btn-secondary h-14 text-lg">Annullér</button>
                    <button type="submit" wire:loading.attr="disabled" x-bind:disabled="!online" class="btn-primary h-14 text-lg disabled:opacity-50">Opret</button>
                </div>
            </form>
        </div>
    @endif

    {{-- Container-panel --}}
    @if ($panel)
        @include('livewire.partials.container-panel', ['panel' => $panel, 'customers' => $customers])
    @endif

    {{-- Arbejdsplan: hele planen --}}
    @if ($work && $planOpen)
        @include('livewire.partials.work-plan', ['work' => $work])
    @endif

    {{-- Arbejdsplan i gang: næste trin nederst på skærmen --}}
    @if ($work && $work['started'])
        @php $next = $work['plan']['steps'][0] ?? null; @endphp
        <div x-show="!dragging" wire:key="plan-bar"
             class="fixed inset-x-0 bottom-0 z-30 border-t-4 border-violet-600 bg-white px-4 py-3 shadow-[0_-8px_24px_rgba(15,23,42,0.15)]">
            <div class="mx-auto flex max-w-6xl flex-wrap items-center gap-3">
                <div class="min-w-0 flex-1">
                    <div class="text-sm font-semibold uppercase tracking-wide text-violet-700">
                        Arbejdsplan for {{ $work['target']->number }}{{ $next ? ' · trin '.($work['done'] + 1).' af '.$work['total'] : '' }}
                    </div>
                    @if ($next)
                        <p class="text-xl leading-snug">
                            Flyt <strong>{{ $next['container']->number }}</strong> fra <strong>{{ $next['fromLabel'] }}</strong>
                            til <strong class="text-violet-800">{{ $next['label'] }}</strong>
                        </p>
                        @if ($work['plan']['problem'])
                            <p class="text-red-700">{{ $work['plan']['problem'] }}</p>
                        @endif
                    @elseif ($work['plan']['blockers'] === 0)
                        <p class="text-xl font-semibold text-emerald-800">Vejen er fri. {{ $work['target']->number }} kan tages nu.</p>
                    @else
                        <p class="text-lg text-red-700">{{ $work['plan']['problem'] }}</p>
                    @endif
                </div>
                <div class="flex flex-wrap gap-2">
                    @if ($next)
                        <button type="button" wire:click="stepDone({{ $next['container']->id }}, @js($next['from']->key()), @js($next['to']->key()))"
                                wire:loading.attr="disabled" x-bind:disabled="!online"
                                class="btn-primary h-14 px-8 text-xl disabled:opacity-50">Udført</button>
                        <button type="button" wire:click="showPlan({{ $next['container']->id }})" class="btn-secondary h-14 text-lg">Skift plads</button>
                    @elseif ($work['plan']['blockers'] === 0)
                        <button type="button" wire:click="finishPlan" class="btn-primary h-14 px-6 text-lg">Åbn {{ $work['target']->number }}</button>
                    @endif
                    <button type="button" wire:click="showPlan" class="btn-secondary h-14 text-lg">Se planen</button>
                    <button type="button" wire:click="stopPlan" class="btn-danger h-14 text-lg">Stop</button>
                </div>
            </div>
        </div>
    @endif
</div>
