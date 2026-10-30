# Udrulning på VPS hos hosting.gl

Lagerstyring er en almindelig Laravel-app med MySQL. Der skal ikke køre Node på serveren; CSS og JavaScript ligger færdigbygget i `public/build`.

Guiden går ud fra en Ubuntu 24.04-VPS, domænet `lager.itxpartner.dk` og det private GitHub-repo `shadowmandk/lager`.

Kører systemet allerede på serveren med SQLite, så spring til [Skift fra SQLite til MySQL](#skift-fra-sqlite-til-mysql).

## Før du starter
- VPS med Ubuntu 24.04, root-adgang via SSH.
- DNS: en A-post for `lager.itxpartner.dk`, der peger på VPS'ens IP. Den skal virke, før HTTPS-certifikatet kan laves.
- Serveren skal kunne lave udgående HTTPS-kald til `github.com` og (senere) `system.microbizz.dk`.

## 1. Klargør serveren (én gang)
Kopiér scriptet op og kør det som root:
```bash
scp deploy/setup-server.sh root@SERVER:
ssh root@SERVER
bash setup-server.sh lager.itxpartner.dk git@github.com:shadowmandk/lager.git din@email.dk
```
Scriptet installerer PHP 8.4, MySQL, nginx, Composer, firewall (kun SSH, HTTP og HTTPS), cron og et gratis HTTPS-certifikat fra Let's Encrypt, der fornyer sig selv. Det opretter:
- brugeren `lager`, som ejer koden i `/var/www/lagerstyring` og kører PHP og cron,
- databasen `lager` med en MySQL-bruger, der kun kan logge ind fra serveren selv. Adgangskoden er tilfældig og gemmes kun i `/home/lager/.lager-db`.

Til sidst skriver scriptet en offentlig nøgle ud. Læg den på GitHub under repoet: **Settings → Deploy keys → Add deploy key**, uden "Allow write access".

Pegede DNS ikke på serveren endnu, skriver scriptet den certbot-kommando, du skal køre, når den gør.

## 2. Første installation
Alt herfra køres som brugeren `lager`:
```bash
sudo -iu lager
git clone git@github.com:shadowmandk/lager.git /var/www/lagerstyring
cd /var/www/lagerstyring
composer install --no-dev --optimize-autoloader
cp .env.production.example .env
cat ~/.lager-db
nano .env
```
Udfyld i `.env`:
- `DB_PASSWORD`: kopiér den fra `cat ~/.lager-db`. De andre `DB_`-linjer passer allerede.
- `ADMIN_NAME`, `ADMIN_EMAIL` og `ADMIN_PASSWORD`: det første admin-login til Opsætning.
- `ACCESS_CODE`: den første 6-cifrede kode til låseskærmen.
- `SEED_DEMO=true` giver fiktive kunder, containere og 10 ugers historik til fremvisning. Sæt `false` for et tomt system.

Opret så nøgle og tabeller:
```bash
php artisan key:generate
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

Hver gang der skubbes til GitHub, kører alle tests automatisk mod både SQLite og MySQL (fanen **Actions** i repoet). Opdatér kun serveren, når den seneste kørsel er grøn.

## Skift fra SQLite til MySQL
Til en server, der allerede kører systemet med SQLite. Alle data flyttes med: lagre, containere, kunder, log, medarbejdere, admin-login, indstillinger og koden til låseskærmen.

Kommandoerne går ud fra, at koden ligger i `/var/www/lagerstyring` og ejes af brugeren `lager`. Er serveren sat op på en anden måde, så ret sti og bruger (fx `www-data`).

**1. Installér MySQL og opret databasen** (som root). Den sidste linje viser den nye adgangskode én gang; den skal bruges i trin 4.
```bash
apt-get install -y mysql-server php8.4-mysql
systemctl restart php8.4-fpm
PASS="$(openssl rand -base64 33 | tr -d '/+=' | cut -c1-32)"
mysql -e "CREATE DATABASE lager CHARACTER SET utf8mb4 COLLATE utf8mb4_danish_ci; CREATE USER 'lager'@'localhost' IDENTIFIED BY '$PASS'; GRANT ALL PRIVILEGES ON lager.* TO 'lager'@'localhost';"
echo "DB_PASSWORD=$PASS"
```

**2. Luk siden og tag en sikker kopi af SQLite-databasen** (som root, så rettigheder ikke er i vejen):
```bash
cd /var/www/lagerstyring
sudo -u lager php artisan down --render="errors::503"
sqlite3 database/database.sqlite ".backup /home/lager/lager-sqlite-kopi.sqlite"
chown lager:lager /home/lager/lager-sqlite-kopi.sqlite
```

**3. Hent den nye version:**
```bash
sudo -iu lager
cd /var/www/lagerstyring
git pull --ff-only
composer install --no-dev --optimize-autoloader
```

**4. Ret `.env`:** fjern linjen `DB_CONNECTION=sqlite` og en eventuel `DB_DATABASE=…sqlite`, og indsæt:
```
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=lager
DB_USERNAME=lager
DB_PASSWORD=den-fra-trin-1
DB_COLLATION=utf8mb4_danish_ci
```

**5. Opret tabellerne i MySQL, flyt data og åbn igen:**
```bash
php artisan optimize:clear
php artisan migrate --force
php artisan lager:fra-sqlite /home/lager/lager-sqlite-kopi.sqlite
php artisan optimize
php artisan up
```
Kommandoen viser, hvor mange rækker der er flyttet pr. tabel. Alle enheder skal låses op igen med den samme kode som før.

**6. Tjek cron:** planlagte kørsler skal køre som `lager`, ikke som root. Ligger der en linje med `schedule:run` i `crontab -l` for root, så flyt den til `/etc/cron.d/lagerstyring`:
```
* * * * * lager cd /var/www/lagerstyring && php artisan schedule:run >> /dev/null 2>&1
```

Behold kopien i `/home/lager/` et stykke tid, indtil alt er tjekket. Selve SQLite-filen bruges ikke længere.

## Adgang
- **Tavle, log og statistik** er låst bag en fælles 6-cifret kode. Enheden låser igen, når skærmen slukkes, når man skifter app eller fane, efter 30 minutter uden aktivitet og med knappen **Lås**. Efter 5 forkerte koder skal man vente et minut, og ventetiden fordobles ved flere fejl.
- **Koden skiftes** under Opsætning → Indstillinger og backup, eller på serveren med `php artisan lager:kode`. Når koden skiftes, låses alle enheder.
- **Opsætning** (`/admin`) har sit eget login med email og adgangskode.
- Siden beder søgemaskiner om ikke at vise den (POC).

## Backup
Der tages en backup hver nat kl. 02 i `storage/backups/` som komprimeret SQL (`lager-ÅÅÅÅ-MM-DD_TTMMSS.sql.gz`), og de seneste 30 gemmes. De kan hentes under Opsætning → Indstillinger og backup. Deploy-scriptet tager også en backup før hver opdatering.

Gem jævnligt en kopi uden for serveren, fx ved at hente den nyeste backup fra Opsætning.

Genskab en backup (overskriver alt i databasen):
```bash
php artisan down
gunzip -c storage/backups/lager-….sql.gz | mysql -u lager -p lager
php artisan up
```

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
- App-log: `storage/logs/laravel-*.log` (én fil pr. dag, 14 dage). Den første linje ved en fejlside fortæller årsagen.
- PHP-fejl: `/var/log/php-lager.log`. nginx: `/var/log/nginx/error.log`.
- Siden hænger i "Systemet opdateres": `sudo -iu lager php /var/www/lagerstyring/artisan up`.
- "Access denied" eller "Connection refused" i loggen: tjek `DB_`-linjerne i `.env`, kør `php artisan optimize`, og tjek at MySQL kører (`systemctl status mysql`).
