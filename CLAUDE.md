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
- Flytning mellem lagre går via status `transit`: containeren holder sin reserverede bufferplads i placeringsfelterne (`Container::location()`), kan ikke tages, og intet kan sættes oven på den. `slot()` gælder kun containere på lager. Brug `Container::ON_SLOT`, når optagne pladser skal tælles.
- Pladsnavne: "Lager 1 · A 3 2" (række, plads fra indgangen, højde nedefra), bufferstakke "Buffer 2 1". Laves af `StorageRow::slotName` og `LocationLabel`.
- Microbizz (`app/Services/Microbizz`) er master for kunder og containeres nummer, navn og kunde; systemet skriver aldrig tilbage. Koblede poster har `microbizz_id` og må ikke rettes her (`isFromMicrobizz()`). Hentningen må aldrig ændre status eller plads for en eksisterende container. Slået fra, når `MICROBIZZ_*` i `.env` er tomme. Tests bruger den falske Microbizz i `tests/Feature/MicrobizzTest.php`.

## Kommandoer
- Tests: `php vendor/bin/pest`
- Byg CSS/JS (committes i `public/build`): `npm run build`
- Nulstil lokal database med demodata: `php artisan migrate:fresh --seed`
- Lokal server: `php artisan serve --port=8092`

## Adgang
- Tavle og log: vælg medarbejder uden kodeord (`EnsureOperator`, også som Livewire persistent middleware).
- Opsætning `/admin`: Laravel-login. Første admin oprettes af `AdminSeeder` fra `ADMIN_*` i `.env`.
