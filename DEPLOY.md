# Udrulning hos Curanet

Systemet er en almindelig Laravel-app med SQLite. Der skal ikke køre Node på serveren; CSS og JavaScript ligger færdigbygget i `public/build`.

## Krav
- PHP 8.3 eller nyere med `pdo_sqlite`, `mbstring`, `openssl`, `fileinfo`
- Composer
- HTTPS på domænet
- Mulighed for et cronjob, der kører hvert minut (til den natlige backup)

## Første installation
1. Læg koden på serveren, f.eks. med `git clone`.
2. Peg domænets dokumentrod på mappen `public`.
3. Installér pakker:
   ```bash
   composer install --no-dev --optimize-autoloader
   ```
4. Opret `.env` ud fra `.env.example` og ret mindst:
   ```
   APP_ENV=production
   APP_DEBUG=false
   APP_URL=https://lager.ditdomæne.dk
   ADMIN_NAME=Thomas
   ADMIN_EMAIL=din@email.dk
   ADMIN_PASSWORD=en-lang-og-unik-adgangskode
   ```
5. Generér nøgle, opret databasen og kør migrationer og startdata (lagerlayout og første admin):
   ```bash
   php artisan key:generate
   touch database/database.sqlite
   php artisan migrate --force
   php artisan db:seed --force
   ```
   Demodata oprettes ikke i produktion. Vil man have dem til en fremvisning, sættes `SEED_DEMO=true` før seed.
6. Fjern `ADMIN_PASSWORD` fra `.env` igen, når admin er oprettet.
7. Sørg for, at webserveren kan skrive i `storage/` og `bootstrap/cache/` og i `database/` (SQLite skriver en journalfil ved siden af databasen).
8. Optimér:
   ```bash
   php artisan config:cache
   php artisan route:cache
   php artisan view:cache
   ```
9. Opret cronjob:
   ```
   * * * * * cd /sti/til/lagerstyring && php artisan schedule:run >> /dev/null 2>&1
   ```
   Det tager en backup hver nat kl. 02 i `storage/backups/` og gemmer de seneste 30. De kan hentes under Opsætning, Indstillinger og backup.

## Opdatering
```bash
git pull
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:cache && php artisan route:cache && php artisan view:cache
```

## Efter installation
Log ind på `/admin` og ret:
- medarbejdernes navne (fire stk.),
- kunder,
- lager 2's rækker, når de rigtige mål kendes,
- minutter pr. flytning.

Opret derefter containerne. De dukker op under "Venter på plads" på tavlen og placeres derfra.
