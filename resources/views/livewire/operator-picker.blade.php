<div class="flex min-h-screen flex-col items-center justify-center gap-8 p-6">
    <div class="text-center">
        <h1 class="text-3xl font-semibold text-slate-800">Hvem kører?</h1>
        <p class="mt-2 text-lg text-slate-600">Tryk på dit navn. Det bruges i loggen, så man kan se, hvem der har flyttet hvad.</p>
    </div>

    @if ($operators->isEmpty())
        <p class="rounded-xl bg-amber-100 px-6 py-4 text-amber-900">
            Der er ingen medarbejdere endnu. En administrator kan oprette dem under opsætning.
        </p>
    @else
        <div class="grid w-full max-w-3xl grid-cols-1 gap-4 sm:grid-cols-2">
            @foreach ($operators as $operator)
                <button type="button" wire:click="choose({{ $operator->id }})"
                    class="flex min-h-28 items-center justify-center gap-4 rounded-2xl bg-white px-6 py-8 text-3xl font-semibold text-slate-800 shadow-md ring-1 ring-slate-200 transition active:scale-[0.98] active:bg-slate-50">
                    <span class="flex h-14 w-14 items-center justify-center rounded-full bg-slate-800 text-2xl text-white">
                        {{ mb_substr($operator->name, 0, 1) }}
                    </span>
                    {{ $operator->name }}
                </button>
            @endforeach
        </div>
    @endif

    <div class="flex items-center gap-6">
        <form method="POST" action="{{ route('lock') }}">
            @csrf
            <button type="submit" class="btn-secondary h-12 gap-2">
                <svg viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>
                Lås
            </button>
        </form>
        <a href="{{ route('admin') }}" class="text-sm text-slate-500 underline underline-offset-4">Opsætning (kontor)</a>
    </div>
</div>
