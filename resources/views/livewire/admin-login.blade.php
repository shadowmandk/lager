<div class="flex min-h-screen items-center justify-center p-6">
    <form wire:submit="login" class="w-full max-w-sm space-y-4 rounded-2xl bg-white p-6 shadow-md ring-1 ring-slate-200">
        <div>
            <h1 class="text-2xl font-semibold">Opsætning</h1>
            <p class="text-slate-600">Log ind som administrator. Opsætning foregår fra kontoret.</p>
        </div>

        <label class="block">
            <span class="text-sm font-medium text-slate-700">E-mail</span>
            <input type="email" wire:model="email" autocomplete="username" required class="mt-1 h-12 w-full">
        </label>

        <label class="block">
            <span class="text-sm font-medium text-slate-700">Adgangskode</span>
            <input type="password" wire:model="password" autocomplete="current-password" required class="mt-1 h-12 w-full">
        </label>

        @error('email') <p class="rounded-lg bg-red-50 px-3 py-2 text-red-700">{{ $message }}</p> @enderror

        <button type="submit" class="btn-primary h-12 w-full text-lg">Log ind</button>

        <a href="{{ route('operator.pick') }}" class="block text-center text-sm text-slate-500 underline underline-offset-4">Tilbage til tavlen</a>
    </form>
</div>
