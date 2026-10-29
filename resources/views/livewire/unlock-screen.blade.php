<div class="flex min-h-screen flex-col items-center justify-center gap-6 bg-slate-800 p-6 text-white"
     x-data="{
        open: false,
        code: '',
        length: {{ $length }},
        busy: false,
        press(d) {
            if (this.busy || this.code.length >= this.length) return;
            this.code += d;
            if (this.code.length === this.length) this.send();
        },
        back() { if (! this.busy) this.code = this.code.slice(0, -1); },
        async send() {
            this.busy = true;
            await $wire.unlock(this.code);
            this.code = '';
            this.busy = false;
        },
     }"
     @keydown.window="if (open && /^[0-9]$/.test($event.key)) press($event.key); if (open && $event.key === 'Backspace') back()">

    <div class="flex flex-col items-center gap-3 text-center">
        <svg viewBox="0 0 24 24" class="h-14 w-14" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true">
            <path d="M3 8l9-4 9 4v8l-9 4-9-4V8z"/><path d="M3 8l9 4 9-4M12 12v8"/>
        </svg>
        <h1 class="text-3xl font-semibold">Lagerstyring</h1>
    </div>

    @unless ($hasCode)
        <p class="max-w-md rounded-xl bg-amber-100 px-5 py-4 text-center text-lg text-amber-900">
            Der er ikke valgt nogen kode endnu. Kontoret vælger den under Opsætning.
        </p>
    @endunless

    <button type="button" x-show="! open" @click="open = true"
            class="flex h-20 w-full max-w-xs items-center justify-center gap-3 rounded-2xl bg-emerald-600 text-2xl font-semibold shadow-lg active:bg-emerald-700">
        <svg viewBox="0 0 24 24" class="h-8 w-8" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 7.5-2"/></svg>
        Lås op
    </button>

    <div x-show="open" x-cloak class="flex w-full max-w-xs flex-col items-center gap-5">
        <p class="text-lg text-slate-300">Tast koden</p>

        <div wire:key="dots-{{ $attemptId }}" @class(['flex gap-3', 'animate-shake' => $error])
             role="status" aria-live="polite" :aria-label="code.length + ' af ' + length + ' cifre tastet'">
            <template x-for="i in length" :key="i">
                <span class="h-4 w-4 rounded-full ring-2 ring-white" :class="i <= code.length ? 'bg-white' : 'bg-transparent'"></span>
            </template>
        </div>

        <p class="min-h-7 text-center text-lg font-medium text-red-300" role="alert">{{ $error }}</p>

        <div class="grid w-full grid-cols-3 gap-3">
            @foreach ([1, 2, 3, 4, 5, 6, 7, 8, 9] as $d)
                <button type="button" @click="press('{{ $d }}')" class="keypad-btn">{{ $d }}</button>
            @endforeach
            <button type="button" @click="open = false; code = ''" class="keypad-btn text-base font-medium text-slate-300">Luk</button>
            <button type="button" @click="press('0')" class="keypad-btn">0</button>
            <button type="button" @click="back()" class="keypad-btn" aria-label="Slet sidste ciffer">
                <svg viewBox="0 0 24 24" class="h-8 w-8" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M9 6h11v12H9l-6-6 6-6z"/><path d="M12.5 9.5l5 5M17.5 9.5l-5 5"/></svg>
            </button>
        </div>
    </div>

    <a href="{{ route('admin') }}" class="mt-4 text-sm text-slate-400 underline underline-offset-4">Opsætning (kontor)</a>
</div>
