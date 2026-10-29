# Udrulning på VPS hos Curanet

Lagerstyring er en almindelig Laravel-app med SQLite. Der skal ikke køre Node på serveren; CSS og JavaScript ligger færdigbygget i `public/build`.

Guiden går ud fra en ny Ubuntu 24.04-VPS, domænet `lager.itxpartner.dk` og et privat GitHub-repo.

## Før du starter
- VPS med Ubuntu 24.04, root-adgang via SSH.
- DNS: en A-post for `lager.itxpartner.dk`, der peger på VPS'ens IP. Den skal virke, før HTTPS-certifikatet kan laves.
- Privat repo på GitHub med koden.
- Serveren skal kunne lave udgående HTTPS-kald til `github.com` og (senere) `system.microbizz.dk`.

## 1. Klargør serveren (én gang)
Kopiér scriptet op og kør det som root:
```bash
scp deploy/setup-server.sh root@SERVER:
ssh root@SERVER
bash setup-server.sh lager.itxpartner.dk git@github.com:ORG/REPO.git din@email.dk
```
Scriptet installerer PHP 8.4, nginx, Composer, firewall (kun SSH, HTTP og HTTPS), cron og et gratis HTTPS-certifikat fra Let's Encrypt, der fornyer sig selv. Det opretter brugeren `lager`, som ejer koden i `/var/www/lagerstyring` og kører PHP.

Til sidst skriver scriptet en offentlig nøgle ud. Læg den på GitHub under repoet: **Settings → Deploy keys → Add deploy key**, uden "Allow write access".

Pegede DNS ikke på serveren endnu, skriver scriptet den certbot-kommando, du skal køre, når den gør.

## 2. Første installation
Alt herfra køres som brugeren `lager`:
```bash
sudo -iu lager
git clone git@github.com:ORG/REPO.git /var/www/lagerstyring
cd /var/www/lagerstyring
composer install --no-dev --optimize-autoloader
cp .env.production.example .env
nano .env
```
Udfyld i `.env`:
- `ADMIN_NAME`, `ADMIN_EMAIL` og `ADMIN_PASSWORD`: det første admin-login til Opsætning.
- `ACCESS_CODE`: den første 6-cifrede kode til låseskærmen.
- `SEED_DEMO=true` giver fiktive kunder, containere og 10 ugers historik til fremvisning. Sæt `false` for et tomt system.

Opret så nøgle og database:
```bash
php artisan key:generate
touch database/database.sqlite
php artisan migrate --force
php artisan db:seed --force
php artisan optimize
```
Fjern derefter `ADMIN_PASSWORD` og `ACCESS_CODE` fra `.env` igen, og kør `php artisan optimize`. De bruges kun første gang; bagefter rettes de i systemet.

Åbn https://lager.itxpartner.dk. Du skal se låseskærmen.

## 3. Opdatering
Når der er skubbet en ny version til GitHub:
```bash
sudo -iu lager /var/www/lagerstyring/deploy/deploy.sh
```
Scriptet tager en backup, viser "Systemet opdateres", henter koden, kører databaseændringer og åbner igen. Det tager normalt under et minut. Fejler noget, bliver siden stående i vedligehold, og scriptet skriver, hvad du skal gøre.

## Adgang
- **Tavle, log og statistik** er låst bag en fælles 6-cifret kode. Enheden låser igen, når skærmen slukkes, når man skifter app eller fane, efter 30 minutter uden aktivitet og med knappen **Lås**. Efter 5 forkerte koder skal man vente et minut, og ventetiden fordobles ved flere fejl.
- **Koden skiftes** under Opsætning → Indstillinger og backup, eller på serveren med `php artisan lager:kode`. Når koden skiftes, låses alle enheder.
- **Opsætning** (`/admin`) har sit eget login med email og adgangskode.
- Siden beder søgemaskiner om ikke at vise den (POC).

## Backup
Der tages en backup hver nat kl. 02 i `storage/backups/`, og de seneste 30 gemmes. De kan hentes under Opsætning → Indstillinger og backup. Deploy-scriptet tager også en backup før hver opdatering.

Gem jævnligt en kopi uden for serveren, fx ved at hente den nyeste backup fra Opsætning.

## Microbizz
Bed kunden oprette en API-bruger i Microbizz, helst kun med læseadgang, og få kontraktnummer og API-nøgle. Skriv dem kun i serverens `.env`, aldrig i mails eller chat:
```
MICROBIZZ_CONTRACT=
MICROBIZZ_APIKEY=
MICROBIZZ_USERNAME=
MICROBIZZ_PASSWORD=
```
Kør `php artisan optimize` bagefter. Gå så til Opsætning → Microbizz:
1. Tryk "Test forbindelse" og se, hvilke grupper og felter udstyret har.
2. Vælg, hvilket udstyr der er containere, og hvilket felt containernummeret står i, og gem.
3. Tryk "Hent nu". Første gang hentes alt, derefter kun ændringer.

Derefter henter systemet selv hver time fra kl. 6 til 18. Hentningen kan også køres med `php artisan microbizz:sync`.

## Efter installation
Log ind på `/admin` og ret:
- medarbejdernes navne,
- koden til låseskærmen, hvis den skal være en anden,
- kunder og deres farver,
- lager 2's rækker, når de rigtige mål kendes,
- minutter pr. flytning.

Opret derefter containerne, eller hent dem fra Microbizz. De dukker op under "Venter på plads" på tavlen og placeres derfra.

## Fra POC til rigtig drift
Demodataene skal væk, før kunden bruger systemet rigtigt:
```bash
sudo -iu lager
cd /var/www/lagerstyring
php artisan backup:run
php artisan migrate:fresh --force
```
Sæt `SEED_DEMO=false`, og læg `ADMIN_PASSWORD` og `ACCESS_CODE` ind igen. Kør `php artisan optimize:clear` (ellers læser seed ikke `.env`) og `php artisan db:seed --force`. Fjern så de to igen, og kør `php artisan optimize`.

## Fejlsøgning
- App-log: `storage/logs/laravel-*.log` (én fil pr. dag, 14 dage).
- PHP-fejl: `/var/log/php-lager.log`. nginx: `/var/log/nginx/error.log`.
- Siden hænger i "Systemet opdateres": `sudo -iu lager php /var/www/lagerstyring/artisan up`.
