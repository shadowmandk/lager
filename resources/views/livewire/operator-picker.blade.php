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

    <a href="{{ route('admin') }}" class="text-sm text-slate-500 underline underline-offset-4">Opsætning (kontor)</a>
</div>
