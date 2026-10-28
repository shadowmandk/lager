@php $col = $c->color(); @endphp
<div wire:key="chip-{{ $c->id }}" class="flex items-stretch overflow-hidden rounded-lg ring-1 ring-slate-200">
    <span class="chip-grip flex w-9 items-center justify-center" style="background: {{ $col['side'] }}; color: {{ $col['text'] }}"
          data-drag-id="{{ $c->id }}" data-drag-kind="{{ $kind }}"
          data-drag-number="{{ $c->number }}" data-drag-label="{{ $c->customer?->name ?? 'Ingen kunde' }}" data-drag-color="{{ $col['side'] }}"
          title="Træk for at placere">
        <svg viewBox="0 0 24 24" class="h-5 w-5" fill="currentColor" aria-hidden="true"><circle cx="9" cy="6" r="1.6"/><circle cx="15" cy="6" r="1.6"/><circle cx="9" cy="12" r="1.6"/><circle cx="15" cy="12" r="1.6"/><circle cx="9" cy="18" r="1.6"/><circle cx="15" cy="18" r="1.6"/></svg>
    </span>
    <button type="button" wire:click="pick({{ $c->id }})" class="min-w-0 flex-1 bg-white px-3 py-2 text-left active:bg-slate-50">
        <span class="block truncate font-semibold">{{ $c->number }} <span class="font-normal text-slate-600">{{ $c->name }}</span></span>
        <span class="block truncate text-sm text-slate-600">{{ $c->customer?->name ?? 'Ingen kunde' }}</span>
    </button>
    <button type="button" wire:click="openPanel({{ $c->id }})" class="flex w-10 items-center justify-center bg-white text-slate-500 active:bg-slate-50" aria-label="Detaljer om {{ $c->number }}">
        <svg viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8v.5"/></svg>
    </button>
</div>
