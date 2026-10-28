# Lagerstyring – projektnoter

Webbaseret styring af skibscontainere på to lagre. Erstatter en magnettavle. Bruges fra tablet i gaffeltruck og fra pc på kontoret.

- Design: `docs/superpowers/specs/2026-10-28-lagerstyring-design.md`
- Plan: `docs/superpowers/plans/2026-10-28-lagerstyring-plan.md`
- Drift: `DEPLOY.md`

## Stak
PHP 8.4 (Herd lokalt), Laravel 13, Livewire 4 (klassekomponenter i `app/Livewire`), Tailwind 4 via Vite, SQLite, Pest. Al brugertekst er dansk.

## Kerneregler (må ikke brydes)
- En række tilgås kun fra dybde 1. Man kan kun tage den øverste container i den forreste ikke-tomme kolonne.
- Man kan kun sætte en container, hvis alt foran er tomt, og den står på gulvet eller på en anden container.
- Logikken bor i `app/Services/RowGrid.php`. Livewire-komponenter må ikke selv afgøre regler; de kalder `PlacementRules`, `MoveService`, `AccessCalculator`, `UndoService` og `LayoutService`.
- Alle ændringer af containere går gennem `MoveService`, så de logges. Fortryd går gennem `UndoService` med de samme regler.
- Fortryd: vinduet er de 15 nyeste fortrydbare handlinger; kun den nyeste ikke-fortrudte kan fortrydes.

## Kommandoer
- Tests: `php vendor/bin/pest`
- Byg CSS/JS (committes i `public/build`): `npm run build`
- Nulstil lokal database med demodata: `php artisan migrate:fresh --seed`
- Lokal server: `php artisan serve --port=8092`

## Adgang
- Tavle og log: vælg medarbejder uden kodeord (`EnsureOperator`, også som Livewire persistent middleware).
- Opsætning `/admin`: Laravel-login. Første admin oprettes af `AdminSeeder` fra `ADMIN_*` i `.env`.
