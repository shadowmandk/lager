<!DOCTYPE html>
<html lang="da">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title') · Lagerstyring</title>
    {{-- Selvstændig styling, så siden også virker, hvis noget andet er galt. --}}
    <style>
        body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center;
               background: #1e293b; color: #fff; font-family: system-ui, -apple-system, "Segoe UI", sans-serif; padding: 24px; box-sizing: border-box; }
        main { max-width: 32rem; text-align: center; }
        svg { width: 56px; height: 56px; }
        h1 { font-size: 1.9rem; margin: 16px 0 8px; }
        p { font-size: 1.15rem; line-height: 1.5; color: #cbd5e1; margin: 0 0 28px; }
        a { display: inline-flex; align-items: center; justify-content: center; min-height: 64px; padding: 0 32px;
            border-radius: 16px; background: #059669; color: #fff; font-size: 1.3rem; font-weight: 600; text-decoration: none; }
        a:active { background: #047857; }
        small { display: block; margin-top: 28px; color: #64748b; }
    </style>
</head>
<body>
<main>
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true">
        <path d="M3 8l9-4 9 4v8l-9 4-9-4V8z"/><path d="M3 8l9 4 9-4M12 12v8"/>
    </svg>
    <h1>@yield('title')</h1>
    <p>@yield('message')</p>
    @hasSection('action')
        @yield('action')
    @else
        <a href="{{ url('/tavle') }}">Til tavlen</a>
    @endif
    <small>Fejlkode @yield('code')</small>
</main>
</body>
</html>
