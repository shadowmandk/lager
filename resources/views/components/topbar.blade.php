@props(['active' => null])

<header class="sticky top-0 z-30 bg-slate-800 text-white shadow">
    <div class="flex flex-wrap items-center gap-2 px-3 py-2 sm:gap-3 sm:px-4">
        <a href="{{ route('board') }}" class="mr-1 flex items-center gap-2 text-lg font-semibold tracking-tight">
            <svg viewBox="0 0 24 24" class="h-7 w-7" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                <path d="M3 8l9-4 9 4v8l-9 4-9-4V8z"/><path d="M3 8l9 4 9-4M12 12v8"/>
            </svg>
            <span class="hidden lg:inline">Lagerstyring</span>
        </a>

        <nav class="flex gap-1">
            <a href="{{ route('board') }}" @class(['nav-btn', 'nav-btn-active' => $active === 'board'])>Tavle</a>
            <a href="{{ route('log') }}" @class(['nav-btn', 'nav-btn-active' => $active === 'log'])>Log</a>
            <a href="{{ route('statistics') }}" @class(['nav-btn', 'nav-btn-active' => $active === 'statistics'])>Statistik</a>
        </nav>

        <div class="flex min-w-0 flex-1 items-center gap-2">
            {{ $slot }}
        </div>

        @isset($currentOperator)
            <div class="flex items-center gap-2">
                <span class="flex items-center gap-2 rounded-lg bg-slate-700 px-3 py-2 text-sm font-medium">
                    <svg viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4 21c1.5-4 4.5-6 8-6s6.5 2 8 6"/></svg>
                    {{ $currentOperator->name }}
                </span>
                <a href="{{ route('operator.pick', ['skift' => 1]) }}" class="nav-btn">Skift</a>
            </div>
        @endisset
    </div>
</header>
