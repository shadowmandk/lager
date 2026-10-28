# Lagerstyring

Elektronisk erstatning for magnettavlen til containerlagrene. Viser alle containere i en isometrisk tavle, forhindrer ulovlige placeringer, beregner hvor mange flytninger det kræver at komme ind til en container, logger alt og kan fortryde de seneste 15 handlinger.

- Design og regler: [docs/superpowers/specs/2026-10-28-lagerstyring-design.md](docs/superpowers/specs/2026-10-28-lagerstyring-design.md)
- Udrulning: [DEPLOY.md](DEPLOY.md)

## Kom i gang lokalt
```bash
composer install
npm install && npm run build
cp .env.example .env && php artisan key:generate
php artisan migrate:fresh --seed
php artisan serve --port=8092
```
Sæt `ADMIN_EMAIL` og `ADMIN_PASSWORD` i `.env` før seed for at få en admin til `/admin`.

## Tests
```bash
php vendor/bin/pest
```
