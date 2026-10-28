<div>
    <x-topbar active="log"/>

    <div class="mx-auto max-w-5xl space-y-3 p-3">
        <div class="flex flex-wrap items-end gap-3 rounded-2xl bg-white p-3 shadow-sm ring-1 ring-slate-200">
            <label class="block">
                <span class="block text-sm text-slate-600">Medarbejder</span>
                <select wire:model.live="operatorFilter" class="mt-1 h-11 min-w-44">
                    <option value="">Alle</option>
                    @foreach ($operators as $op)
                        <option value="{{ $op->id }}">{{ $op->name }}</option>
                    @endforeach
                </select>
            </label>
            <label class="block flex-1">
                <span class="block text-sm text-slate-600">Containernummer</span>
                <input type="search" wire:model.live.debounce.300ms="containerFilter" class="mt-1 h-11 w-full" placeholder="4012">
            </label>
            <p class="w-full text-sm text-slate-500">
                De seneste 15 flytninger og rettelser kan fortrydes, én ad gangen og nyeste først.
            </p>
        </div>

        <ul class="divide-y divide-slate-100 overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-slate-200">
            @forelse ($entries as $e)
                @php
                    $inWindow = isset($windowIds[$e->id]);
                    $isCandidate = $e->id === $candidateId;
                @endphp
                <li wire:key="log-{{ $e->id }}" @class(['flex flex-wrap items-center gap-3 px-4 py-3', 'bg-slate-50 text-slate-400' => $e->isUndone()])>
                    <div class="w-28 shrink-0 text-sm">
                        <div class="font-medium text-slate-700">{{ $e->created_at->format('d.m.Y') }}</div>
                        <div class="text-slate-500">{{ $e->created_at->format('H:i:s') }}</div>
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-baseline gap-x-2">
                            <span @class([
                                'rounded px-2 py-0.5 text-sm font-semibold',
                                'bg-sky-100 text-sky-800' => $e->action === 'moved',
                                'bg-orange-100 text-orange-800' => $e->action === 'delivered',
                                'bg-emerald-100 text-emerald-800' => in_array($e->action, ['returned', 'placed', 'created']),
                                'bg-violet-100 text-violet-800' => $e->action === 'edited',
                                'bg-slate-200 text-slate-700' => $e->action === 'undone',
                                'line-through' => $e->isUndone(),
                            ])>{{ $e->label() }}</span>
                            <span class="text-lg font-semibold text-slate-900">{{ $e->container?->number }}</span>
                            <span class="text-slate-600">{{ $e->container?->customer?->name }}</span>
                            <span class="text-sm text-slate-500">af {{ $e->actorName() }}</span>
                        </div>
                        @if ($e->action === 'edited' || ($e->action === 'undone' && $e->from_label === $e->to_label))
                            <div class="text-sm text-slate-600">
                                @if ($e->from_name !== $e->to_name) Navn: {{ $e->from_name ?? '–' }} → {{ $e->to_name ?? '–' }}. @endif
                                @if ($e->from_customer_id !== $e->to_customer_id) Kunde: {{ $e->fromCustomer?->name ?? 'ingen' }} → {{ $e->toCustomer?->name ?? 'ingen' }}. @endif
                            </div>
                        @elseif ($e->from_label || $e->to_label)
                            <div class="text-sm text-slate-600">{{ $e->from_label ?? 'Ny' }} → {{ $e->to_label }}</div>
                        @endif
                        @if ($e->action === 'returned' && $e->from_customer_id !== $e->to_customer_id)
                            <div class="text-sm text-slate-600">Kunde: {{ $e->fromCustomer?->name ?? 'ingen' }} → {{ $e->toCustomer?->name ?? 'ingen' }}</div>
                        @endif
                    </div>
                    <div class="shrink-0">
                        @if ($e->isUndone())
                            <span class="text-sm">Fortrudt</span>
                        @elseif ($isCandidate)
                            <button type="button" wire:click="askUndo({{ $e->id }})" class="btn-danger h-12">Fortryd</button>
                        @elseif ($inWindow)
                            <span class="text-sm text-slate-500">Fortryd de nyere først</span>
                        @endif
                    </div>
                </li>
            @empty
                <li class="px-4 py-6 text-slate-500">Ingen handlinger endnu.</li>
            @endforelse
        </ul>

        {{ $entries->links() }}
    </div>

    @if ($notice)
        <div wire:key="notice-{{ $noticeId }}" x-data="{ show: true }" x-init="setTimeout(() => show = false, 6000)" x-show="show"
             @class([
                 'fixed left-1/2 top-20 z-50 w-[min(92vw,36rem)] -translate-x-1/2 rounded-xl px-5 py-3 text-lg font-medium shadow-lg',
                 'bg-emerald-600 text-white' => $noticeType === 'ok',
                 'bg-red-600 text-white' => $noticeType === 'error',
             ]) @click="show = false" role="status">{{ $notice }}</div>
    @endif

    @if ($confirmEntry)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 p-4">
            <div class="w-full max-w-md rounded-2xl bg-white p-5 shadow-xl" role="dialog" aria-modal="true">
                <h2 class="text-2xl font-semibold">Fortryd handling?</h2>
                <p class="mt-3 text-lg">
                    {{ $confirmEntry->label() }} af <strong>{{ $confirmEntry->container?->number }}</strong>
                    @if ($confirmEntry->action === 'edited')
                        rulles tilbage, så navn og kunde bliver som før.
                    @else
                        rulles tilbage. Containeren sendes tilbage til: <strong>{{ $confirmEntry->from_label }}</strong>.
                    @endif
                </p>
                <div class="mt-6 grid grid-cols-2 gap-3">
                    <button type="button" wire:click="cancelUndo" class="btn-secondary h-14 text-lg">Annullér</button>
                    <button type="button" wire:click="undo" class="btn-primary h-14 text-lg">Fortryd</button>
                </div>
            </div>
        </div>
    @endif
</div>
