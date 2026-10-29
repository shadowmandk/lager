#!/usr/bin/env bash
#
# Lægger den nyeste version fra GitHub på serveren. Køres som brugeren "lager":
#
#   sudo -iu lager /var/www/lagerstyring/deploy/deploy.sh
#
# Siden viser "Systemet opdateres" imens (typisk under et minut). Der tages en backup først.
#
set -euo pipefail

cd "$(dirname "$0")/.."

[[ -f .env ]] || { echo "Mangler .env. Følg 'Første installation' i DEPLOY.md."; exit 1; }

echo "==> Backup"
php artisan backup:run

echo "==> Vedligehold"
php artisan down --render="errors::503" --retry=15
trap 'echo "!! Opdateringen fejlede. Siden står stadig i vedligehold. Ret fejlen og kør scriptet igen, eller åbn med: php artisan up"' ERR

echo "==> Kode"
git pull --ff-only

echo "==> Pakker"
composer install --no-dev --optimize-autoloader --no-interaction --no-progress

echo "==> Database"
php artisan migrate --force

echo "==> Cache"
php artisan optimize:clear >/dev/null
php artisan optimize

php artisan up
echo "==> Færdig: $(git log -1 --format='%h %s')"
