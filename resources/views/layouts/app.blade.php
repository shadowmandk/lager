<!DOCTYPE html>
<html lang="da">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#1e293b">
    <meta name="robots" content="noindex, nofollow">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ isset($title) ? $title.' · ' : '' }}Lagerstyring</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-slate-100 text-slate-900 antialiased"
      @if (request()->routeIs('operator.pick', 'board', 'log', 'statistics'))
          data-lock="{{ json_encode(['lockUrl' => route('lock'), 'unlockUrl' => route('unlock'), 'idleMinutes' => \App\Support\DeviceLock::IDLE_MINUTES]) }}"
      @endif>
    {{ $slot }}
</body>
</html>
