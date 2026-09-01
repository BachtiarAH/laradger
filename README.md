# Laradger — API (Backend)

Laravel backend untuk Laradger. Draft v1 — iterasi bareng setelah ini.

## Stack

- **PHP** ^8.3
- **Laravel** ^13.8
- **Laravel Sanctum** ^4.3 (API auth)
- **Pest** ^5.0 + pest-plugin-laravel (testing)
- **Laravel Pint** (formatting) & **Laravel Boost** (AI guidelines)
- DB default **SQLite**, queue/cache/session `database`

## Quick Start

```sh
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan migrate
composer dev
```

`composer dev` jalanin 3 proses concurrently:
- `php artisan serve` (server)
- `php artisan queue:listen --tries=1 --timeout=0` (queue)
- `npm run dev` (vite)

> Alternatif one-liner setup: `composer setup`

## Scripts

| Command | Fungsi |
|---------|--------|
| `composer dev` | serve + queue + vite |
| `composer test` / `php artisan test --compact` | jalanin Pest |
| `php artisan test --filter=testName` | filter test tertentu |
| `vendor/bin/pint --dirty` | format file yang diubah (**wajib** setelah edit PHP) |
| `php artisan migrate` | migrasi DB |
| `php artisan boost:install` | install Boost guidelines |

## Env Penting

Lihat `.env.example`:

```
APP_NAME=Laravel
APP_URL=http://localhost
DB_CONNECTION=sqlite
QUEUE_CONNECTION=database
SESSION_DRIVER=database
CACHE_STORE=database
```

## Struktur

```
app/
database/ (migrations, factories, seeders)
routes/ (api.php, web.php)
tests/ (Pest)
config/
```

Buat file via artisan:

```sh
php artisan make:model Post --no-interaction
php artisan make:test --pest SomeTest
php artisan make:controller Api/PostController --no-interaction
```

## Konvensi

- Cek `.ai/rules/index.md` sebelum nulis kode
- Rekam keputusan durable via `record-rule`
- Jangan tambah dependency tanpa approval
- Format selalu pakai Pint: `vendor/bin/pint --dirty`

## Roadmap

- [ ] Deskripsi domain Laradger (ledger apa?)
- [ ] Dokumentasi endpoint API
- [ ] Seed & factory yang relevan
- [ ] CI + coverage

## Lisensi

MIT
