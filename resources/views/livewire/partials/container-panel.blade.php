@php
    /** @var \App\Models\Container $c */
    $c = $panel['container'];
    $access = $panel['access'];
    $col = $c->color();
@endphp

<div class="fixed inset-0 z-40 flex justify-end bg-slate-900/40" wire:key="panel-{{ $c->id }}" wire:click.self="closePanel">
    <div class="flex h-full w-full max-w-md flex-col overflow-y-auto bg-white shadow-2xl" role="dialog" aria-modal="true" aria-labelledby="panel-title">
        <div class="flex items-start justify-between gap-3 p-4" style="border-top: 8px solid {{ $col['side'] }}">
            <div>
                <h2 id="panel-title" class="text-3xl font-bold">{{ $c->number }}</h2>
                <p class="text-lg text-slate-700">{{ $c->name ?: 'Uden navn' }}</p>
                <p class="text-slate-600">{{ $c->customer?->label() ?? 'Ingen kunde' }}</p>
            </div>
            <button type="button" wire:click="closePanel" class="flex h-12 w-12 items-center justify-center rounded-lg bg-slate-100 text-slate-600" aria-label="Luk">
                <svg viewBox="0 0 24 24" class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M6 6l12 12M18 6L6 18"/></svg>
            </button>
        </div>

        <div class="space-y-4 px-4 pb-6">
            @if ($c->isInTransit())
                <div class="rounded-xl bg-sky-50 p-4 ring-1 ring-sky-200">
                    <div class="text-sm font-semibold uppercase tracking-wide text-sky-700">I transit</div>
                    <p class="mt-1 text-lg">Skal sættes på</p>
                    <p class="text-2xl font-bold text-sky-900">{{ \App\Services\LocationLabel::place($c->location()) }}</p>
                    <p class="mt-2 text-slate-700">Pladsen er reserveret. Tryk på knappen, når containeren står der.</p>
                    <button type="button" wire:click="prepareArrive({{ $c->id }})" class="btn-primary mt-3 h-14 w-full text-lg">Sat i buffer</button>
                </div>
            @else
                <div class="rounded-xl bg-slate-50 p-3">
                    <div class="text-sm text-slate-500">Placering</div>
                    <div class="text-lg font-semibold">{{ $panel['location'] }}</div>
                </div>
            @endif

            @if ($access)
                <div @class(['rounded-xl p-3', 'bg-emerald-50' => $access['moves'] === 0, 'bg-amber-50' => $access['moves'] > 0])>
                    <div class="text-sm font-semibold uppercase tracking-wide text-slate-500">Kom ind til</div>
                    @if ($access['moves'] === 0)
                        <p class="mt-1 text-xl font-semibold text-emerald-800">Kan tages nu</p>
                    @else
                        <p class="mt-1 text-xl font-semibold text-amber-900">
                            {{ $access['moves'] }} {{ $access['moves'] === 1 ? 'container' : 'containere' }} skal flyttes først
                        </p>
                        <p class="text-amber-900">
                            Cirka {{ $access['minutes'] >= 60 ? intdiv($access['minutes'], 60).' t '.($access['minutes'] % 60).' min' : $access['minutes'].' min' }}
                            <span class="text-amber-800/80">({{ $access['minutes_per_move'] }} min pr. flytning)</span>
                        </p>
                        <div class="mt-2 flex flex-wrap gap-1">
                            @foreach ($access['blockers'] as $i => $b)
                                <button type="button" wire:click="openPanel({{ $b->id }})" class="rounded-md bg-white px-2 py-1 text-sm ring-1 ring-amber-200">
                                    <span class="text-amber-700">{{ $i + 1 }}.</span> {{ $b->number }}
                                </button>
                            @endforeach
                        </div>
                        <p class="mt-2 text-sm text-slate-700">
                            Ledige pladser at parkere på lige nu: {{ $access['buffer_free'] }} i bufferen og {{ $access['warehouse_free'] }} i lagerets andre rækker.
                            @if ($access['buffer_free'] + $access['warehouse_free'] < $access['moves'])
                                <strong class="text-red-700">Det er ikke nok til alle {{ $access['moves'] }}.</strong>
                            @endif
                        </p>
                        <button type="button" wire:click="openPlan({{ $c->id }})" class="btn-primary mt-3 h-14 w-full text-lg">
                            {{ $panel['planActive'] ? 'Fortsæt arbejdsplan' : 'Lav arbejdsplan' }}
                        </button>
                    @endif
                </div>
            @endif

            @unless ($c->isInTransit())
                <div class="grid grid-cols-2 gap-2">
                    @if (! $c->isStored() || $panel['takeReason'] === null)
                        <button type="button" wire:click="pick({{ $c->id }})" class="btn-primary h-14 text-lg">
                            {{ $c->isStored() ? 'Flyt' : 'Sæt på plads' }}
                        </button>
                    @endif
                    @if ($c->isStored() && $panel['takeReason'] === null)
                        <button type="button" wire:click="prepare({{ $c->id }}, @js($c->slot()->key()), 'deliver')" class="btn-secondary h-14 text-lg">Udlevér</button>
                    @endif
                </div>
            @endunless

            @if ($c->isStored() && $panel['takeReason'] === null)
                @foreach ($panel['transfers'] as $t)
                    <div>
                        <button type="button" @disabled($t['slot'] === null)
                                @if ($t['slot']) wire:click="prepareTransfer({{ $c->id }}, @js($c->slot()->key()), {{ $t['warehouse']->id }})" @endif
                                class="btn-secondary flex h-14 w-full items-center justify-center gap-2 text-lg disabled:opacity-50">
                            <svg viewBox="0 0 24 24" class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M4 12h14m-5-5l5 5-5 5"/></svg>
                            Flyt til {{ $t['warehouse']->name }}
                        </button>
                        <p class="mt-1 text-sm {{ $t['slot'] ? 'text-slate-600' : 'text-red-700' }}">
                            {{ $t['slot'] ? 'Får bufferplads '.$t['label'].'.' : $t['reason'] }}
                        </p>
                    </div>
                @endforeach
            @endif

            <form wire:submit="savePanel" class="space-y-3 rounded-xl ring-1 ring-slate-200 p-3">
                <div class="text-sm font-semibold uppercase tracking-wide text-slate-500">Ret oplysninger</div>
                <label class="block">
                    <span class="text-sm text-slate-600">Navn</span>
                    <input type="text" wire:model="editName" maxlength="100" class="mt-1 h-12 w-full rounded-lg border-slate-300 text-lg">
                </label>
                <label class="block">
                    <span class="text-sm text-slate-600">Kunde</span>
                    <select wire:model="editCustomerId" class="mt-1 h-12 w-full rounded-lg border-slate-300 text-lg">
                        <option value="">Ingen kunde</option>
                        @foreach ($customers as $cu)
                            <option value="{{ $cu->id }}">{{ $cu->name }} ({{ $cu->number }})</option>
                        @endforeach
                    </select>
                </label>
                @error('editName') <p class="text-sm text-red-700">{{ $message }}</p> @enderror
                <button type="submit" class="btn-secondary h-12 w-full">Gem</button>
            </form>

            <div>
                <div class="mb-1 text-sm font-semibold uppercase tracking-wide text-slate-500">Seneste handlinger</div>
                <ul class="divide-y divide-slate-100 text-sm">
                    @forelse ($panel['history'] as $h)
                        <li class="py-2">
                            <div class="flex justify-between gap-2">
                                <span @class(['font-semibold', 'line-through text-slate-400' => $h->isUndone()])>{{ $h->label() }}</span>
                                <span class="text-slate-500">{{ $h->created_at->format('d.m H:i') }} · {{ $h->actorName() }}</span>
                            </div>
                            @if ($h->from_label || $h->to_label)
                                <div class="text-slate-600">{{ $h->from_label ?? '–' }} → {{ $h->to_label ?? '–' }}</div>
                            @endif
                        </li>
                    @empty
                        <li class="py-2 text-slate-500">Ingen endnu.</li>
                    @endforelse
                </ul>
            </div>
        </div>
    </div>
</div>
