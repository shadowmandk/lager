# Implementeringsplan – lagerstyring

Bygger på `docs/superpowers/specs/2026-10-28-lagerstyring-design.md`. Hvert trin afsluttes med grønne tests og et commit.

## Trin 1 – Projektskelet
- `composer create-project laravel/laravel` (Laravel 13), SQLite, dansk locale og tidszone `Europe/Copenhagen`.
- `composer require livewire/livewire`, Pest som testrunner.
- Tailwind 4 via Vite; byg CSS én gang, commit `public/build`.
- `.claude/launch.json` i `C:\mailsecurity` får en `lagerstyring`-konfiguration på port 8092.

## Trin 2 – Data
- Migrationer: `warehouses`, `zones`, `storage_rows`, `customers`, `containers`, `operators`, `activity_log`, `settings`.
- Unik indeks på `containers(storage_row_id, depth, level)`.
- Modeller med relationer og en lille `Slot`-værdiobjekt (række, dybde, højde) med `label()` som "Lager 1 · A · 3/2".
- `LayoutSeeder` (spec afsnit 2), `DemoSeeder` (kunder, 4 brugere, ca. 60 containere placeret lovligt), `AdminSeeder` (admin fra `.env`).

## Trin 3 – Regler (TDD)
- `PlacementRules`: `canTake`, `canPlace`, `legalTargets`, `blockedBehind`.
- `MoveService`: `move`, `deliver`, `return`, `place`, `edit`, alle i transaktion med forventet kildeplads; kaster `MoveRejected` med dansk besked.
- `AccessCalculator`: blokerende containere i tagerækkefølge, minutter, ledig kapacitet.
- `UndoService`: `undoable()` (15 nyeste ikke-fortrudte, fortrydbare typer), `canUndo`, `undo`.
- Pest-tests for alt i spec afsnit 10.

## Trin 4 – Tavle
- Middleware `operator.selected`; side `/` vælg bruger, `/tavle` tavlen.
- Livewire `Board`: faner pr. lager, `wire:poll.3s` (pauses under træk), udleveret-liste, bekræftelsesmodal, kald til `MoveService`.
- Blade-komponent `iso-row` (SVG med containere i skibscontainer-stil og stiplede tomme pladser).
- Alpine `dragBoard`: long-press 250 ms, spøgelsesbrik følger fingeren, lovlige mål fra serveren (data-attribut pr. brik), slip → `$wire.prepareMove(...)`.
- Offline-bjælke via `navigator.onLine` + Livewire request-fejl.

## Trin 5 – Søg, panel og log
- Søgefelt på tavlen (dæmper ikke-træffere, resultatliste på tværs af lagre).
- Container-panel (slide-over): data, kom-ind-til, historik, redigér navn/kunde.
- Livewire `ActivityLog` på `/log` med filtre og fortryd.

## Trin 6 – Admin
- Login-side `/admin/login` (simpel Livewire-form, Laravel `Auth::attempt`), logout.
- `/admin`: faner for layout, kunder, brugere, containere, indstillinger og backup.

## Trin 7 – Drift og verifikation
- Artisan `backup:run` (kopierer SQLite-filen, beholder 30), planlagt dagligt kl. 02.
- `DEPLOY.md` til Curanet.
- Fuld testkørsel, manuel browserkontrol i tablet-størrelse, commit.
