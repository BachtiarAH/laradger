# Laradger — API (Backend)

Laravel backend untuk Laradger. Draft v1 — iterasi bareng setelah ini.

## Stack

- **PHP** ^8.5
- **Laravel** ^13.8
- **Laravel Sanctum** ^4.3 (API auth)
- **Pest** ^5.0 + pest-plugin-laravel (testing)
- **Laravel Pint** (formatting) & **Laravel Boost** (AI guidelines)
- DB default **SQLite**, queue/cache/session `database`
- **Docker** untuk local & server: nginx + php-fpm 8.5 (tanpa Node/Vite — aplikasi ini API-only)

## Menjalankan di Local

### Opsi A — Docker (disarankan)

Prasyarat: Docker Desktop (backend WSL2 di Windows) dengan Compose v2.

```sh
cp .env.example .env      # sekali saja, pastikan APP_URL=http://localhost:8000
docker compose up -d --build
```

Cek: <http://localhost:8000/up> harus balas `200`.

| Service | Port host | Fungsi |
|---------|-----------|--------|
| `web` | `8000` | nginx, satu-satunya pintu masuk HTTP |
| `app` | — | php-fpm 8.5 |
| `queue` | — | `queue:work` |
| `scheduler` | — | `schedule:work` (jalankan `journal-templates:process` tiap 06:00) |
| `mailpit` | `8025` | mail catcher, aktifkan dengan `--profile mail` |

Perintah harian:

| Kebutuhan | Perintah |
|-----------|----------|
| Test | `docker compose exec app php artisan test --compact` |
| Format | `docker compose exec app vendor/bin/pint --dirty` |
| Artisan | `docker compose exec app php artisan <command>` |
| Tinker | `docker compose exec app php artisan tinker` |
| Log | `docker compose logs -f app` (atau `queue`, `scheduler`, `web`) |
| Shell | `docker compose exec app sh` |
| Stop | `docker compose down` |
| Reset total (vendor + DB) | `docker compose down -v` |

Yang perlu diketahui:

- `vendor/` ada di volume `app-vendor`, **bukan** di folder project. Jadi `php`/`composer` di host tidak melihat dependency — selalu lewat `docker compose exec app`.
- Migrasi otomatis jalan saat container `app` start (`RUN_MIGRATIONS=true`). `queue` dan `scheduler` sengaja tidak ikut migrasi dan menunggu `app` healthy.
- Kode di-bind mount, jadi edit file langsung terlihat. PHP-fpm tanpa opcache di dev, tidak perlu restart.
- Xdebug (opsional): build dengan `WITH_XDEBUG=true docker compose build app`, lalu set `XDEBUG_MODE=debug` di `.env` dan `docker compose up -d app` (default `off`, jadi tidak ada overhead). Pasang listener di IDE pada `host.docker.internal:9003`.
- Mail catcher: `docker compose --profile mail up -d`, lalu set `MAIL_MAILER=smtp`, `MAIL_HOST=mailpit`, `MAIL_PORT=1025`.

#### Ganti database

SQLite adalah default (file `database/database.sqlite`, WAL + `busy_timeout` aktif). Untuk MySQL/PostgreSQL tambahkan overlay:

```sh
docker compose -f docker-compose.yml -f docker-compose.mysql.yml up -d
docker compose -f docker-compose.yml -f docker-compose.pgsql.yml up -d
```

Overlay menyuntik `DB_CONNECTION`/`DB_HOST` ke `app`, `queue`, dan `scheduler` sekaligus, jadi tidak mungkin beda database antar proses. Kredensial diambil dari `.env` (`DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`). Reset datanya: `docker compose -f docker-compose.yml -f docker-compose.mysql.yml down -v`.

> **Belum bisa dipakai:** skema saat ini belum portabel ke MySQL/PostgreSQL — lihat *Known issues*.

#### Troubleshooting local

| Gejala | Solusi |
|--------|--------|
| `Bind for 0.0.0.0:8000 failed` | Port dipakai proses lain. Set `APP_PORT=8001` di `.env`, lalu `docker compose up -d` |
| `attempt to write a readonly database` | Permission SQLite. `docker compose restart app` (entrypoint memperbaiki owner), atau `docker compose exec -u root app chown -R www-data:www-data database storage bootstrap/cache` |
| `database is locked` | Biasanya karena bind mount Windows + WAL. Hentikan sementara `queue`/`scheduler`, atau pindahkan DB ke volume dengan `DB_DATABASE=/var/lib/laradger/database.sqlite` + mount volume di service |
| Build gagal `invalid file request public/storage` | Ada symlink `public/storage` dari host. Hapus (`rm public/storage`); sudah dikecualikan di `.dockerignore` |
| Perubahan `.env` tidak terpakai | `docker compose up -d --force-recreate app queue scheduler` |

### Opsi B — Native (tanpa Docker)

Butuh PHP 8.5+, Composer, dan Node (opsional, hanya untuk aset Vite).

```sh
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
composer dev
```

`composer dev` menjalankan 3 proses: `php artisan serve`, `queue:listen`, dan `npm run dev`. Alternatif one-liner: `composer setup`.

## Menjalankan di Server (Production)

Image dibangun oleh GitHub Actions dan di-push ke GHCR; server hanya menarik image dan menjalankan compose. Tidak ada PHP, Composer, atau Node di server.

### Prasyarat server

- Docker Engine + plugin `docker compose`:

  ```sh
  curl -fsSL https://get.docker.com | sh
  sudo usermod -aG docker "$USER"   # logout/login sekali supaya tidak perlu sudo
  docker compose version
  ```

- Reverse proxy di host (nginx/Caddy) untuk TLS dan domain — compose hanya bind ke `127.0.0.1:8080`
- Akses baca ke package GHCR (PAT dengan scope `read:packages` kalau package private)

### Isi folder deploy

Kalau memakai image dari GHCR, folder ini **bukan** clone repo — cukup dua file:

```
/srv/laradger/
├── docker-compose.prod.yml   # copy dari repo, update manual kalau berubah
└── .env                      # rahasia, tidak pernah masuk repo
```

Kalau memilih build di server, folder ini adalah `git clone` repo (lihat *Tanpa GHCR* di bawah).

### `.env` production

```dotenv
APP_NAME=Laradger
APP_ENV=production
APP_DEBUG=false
APP_KEY=base64:...            # generate sekali, jangan pernah diganti
APP_URL=https://api.example.com

DB_CONNECTION=sqlite
DB_DATABASE=/var/lib/laradger/database.sqlite

LOG_CHANNEL=stderr
QUEUE_CONNECTION=database
SESSION_DRIVER=database
CACHE_STORE=database

IMAGE_REPOSITORY=ghcr.io/owner/laradger
IMAGE_TAG=latest
```

Generate `APP_KEY` tanpa memasang PHP di server:

```sh
docker run --rm php:8.5-cli php -r "echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;"
```

`APP_ENV`/`APP_DEBUG`/`RUN_MIGRATIONS` dipaksa oleh `docker-compose.prod.yml`, jadi tidak bisa salah setting. Container akan **menolak start** kalau `APP_KEY` kosong.

### Deploy manual

```sh
cd /srv/laradger

echo "$GHCR_TOKEN" | docker login ghcr.io -u <github-user> --password-stdin

docker compose -f docker-compose.prod.yml pull
docker compose -f docker-compose.prod.yml run --rm app php artisan migrate --force --step
docker compose -f docker-compose.prod.yml up -d --remove-orphans

docker compose -f docker-compose.prod.yml ps
curl -f http://127.0.0.1:8080/up
```

Urutannya penting: `migrate` dijalankan sebagai container sekali-pakai **sebelum** `up -d`, supaya request tidak pernah dilayani kode baru di atas skema lama.

### Reverse proxy di host

Nginx:

```nginx
location / {
    proxy_pass http://127.0.0.1:8080;
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
    proxy_read_timeout 65s;
}
```

Caddy:

```caddy
api.example.com {
    reverse_proxy 127.0.0.1:8080
}
```

Karena TLS berakhir di host, pastikan `bootstrap/app.php` memakai `trustProxies` yang sesuai kalau URL/redirect terlihat `http://` atau cookie `secure` bermasalah.

### Deploy otomatis (GitHub Actions)

Alur: `test` → `build` (2 image: app + web, tag `<sha>` dan `latest`) → `deploy` (SSH).

Secret yang dibutuhkan:

| Secret | Isi |
|--------|-----|
| `DEPLOY_HOST`, `DEPLOY_USER`, `DEPLOY_PORT` | akses SSH server |
| `DEPLOY_SSH_KEY` | private key untuk SSH |
| `DEPLOY_PATH` | folder deploy, mis. `/srv/laradger` |
| `GHCR_TOKEN` | PAT `read:packages` untuk `docker login` di server |

Catatan operasional:

- `docker-compose.prod.yml` **tidak** ikut ter-update otomatis. Kalau file itu berubah di repo, copy ulang ke server.
- Rollback: set `IMAGE_TAG=sha-<commit-lama>` di `.env`, lalu `docker compose -f docker-compose.prod.yml up -d`. Migrasi database **tidak** ikut di-rollback.
- Kalau `.env` berubah: `docker compose -f docker-compose.prod.yml up -d --force-recreate`.

### Tanpa GHCR — build di server

Kalau server tidak mau bergantung ke GHCR/CI, image bisa dibangun di server dari source. Butuh repo (build context) di server dan `docker-compose.prod.build.yml`.

```sh
git clone <repo-url> /srv/laradger && cd /srv/laradger
cp .env.example .env      # isi seperti contoh .env production di atas

# .env: beri nama image lokal supaya stabil
# IMAGE_REPOSITORY=laradger
# IMAGE_TAG=local

# supaya tidak perlu menulis -f berulang
export COMPOSE_FILE=docker-compose.prod.yml:docker-compose.prod.build.yml

docker compose build --pull
docker compose run --rm app php artisan migrate --force --step
docker compose up -d --remove-orphans
curl -f http://127.0.0.1:8080/up
```

Update berikutnya: `git pull` lalu ulangi tiga perintah terakhir (`build` → `migrate` → `up -d`). `build` hanya mengulang layer yang berubah, jadi biasanya cepat setelah build pertama.

Catatan:

- `COMPOSE_FILE` memakai pemisah `:` di Linux/macOS; di PowerShell pakai `;`.
- Build di server butuh RAM (minimal ~1–2 GB) dan waktu build pertama beberapa menit. Kalau memungkinkan, tetap pakai jalur GHCR + CI.
- Pilih salah satu jalur: **CI + GHCR** (disarankan) atau **build di server**. Kalau build di server, isi `IMAGE_TAG` manual (mis. `local-$(date +%F)`) supaya image lama tetap ada dan rollback masih mungkin.
- Di jalur build, `COMPOSE_FILE` sudah menunjuk kedua file, jadi perintah di tabel *Operasional harian* cukup `docker compose logs -f app` (tanpa `-f ...`).

### Operasional harian

| Kebutuhan | Perintah |
|-----------|----------|
| Log | `docker compose -f docker-compose.prod.yml logs -f app` |
| Status + health | `docker compose -f docker-compose.prod.yml ps` |
| Artisan | `docker compose -f docker-compose.prod.yml exec app php artisan <command>` |
| Restart worker | `docker compose -f docker-compose.prod.yml exec app php artisan queue:restart` |
| Backup SQLite | lihat di bawah |

Backup database (SQLite di volume `laradger-prod_sqlite`):

```sh
docker compose -f docker-compose.prod.yml exec -T app \
  sqlite3 /var/lib/laradger/database.sqlite ".backup /tmp/db-backup.sqlite"
docker compose -f docker-compose.prod.yml cp app:/tmp/db-backup.sqlite ./db-backup-$(date +%F).sqlite
```

Data yang persisten hanya dua volume: `laradger-prod_sqlite` (database) dan `laradger-prod_storage` (log, cache, file upload). `docker compose down` tidak menghapusnya; `down -v` menghapusnya.

## Scripts

Kalau pakai Docker, semua perintah artisan/composer di bawah dijalankan lewat container: `docker compose exec app php artisan ...`, `docker compose exec app vendor/bin/pint --dirty`, dst.

| Command | Fungsi |
|---------|--------|
| `composer dev` | serve + queue + vite |
| `composer test` / `php artisan test --compact` | jalanin Pest |
| `php artisan test --filter=testName` | filter test tertentu |
| `vendor/bin/pint --dirty` | format file yang diubah (**wajib** setelah edit PHP) |
| `php artisan migrate` | migrasi DB |
| `php artisan boost:install` | install Boost guidelines |

## Env Penting

Lihat `.env.example` (bagian `Docker / docker compose` di paling bawah untuk `APP_PORT`, `IMAGE_REPOSITORY`, `IMAGE_TAG`, dan kredensial overlay DB):

```
APP_NAME=Laravel
APP_URL=http://localhost:8000   # dev Docker; di server pakai https://domain
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
docker/ (php + nginx config, entrypoint)
Dockerfile, docker-compose*.yml (dev, prod, prod build, overlay mysql/pgsql)
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

## Known issues

- **Skema belum portabel ke MySQL/PostgreSQL.** `docker-compose.mysql.yml` dan `docker-compose.pgsql.yml` sudah disiapkan, tapi `migrate` gagal karena:
  - MySQL: `accounts.code` dan `journals.reference` bertipe `TEXT` tapi dipakai sebagai unique index (error 1170).
  - PostgreSQL: foreign key self-reference (`accounts.parent_id`, `journals.reverse_from_id`) dibuat sebelum primary key, jadi error 42830.
  
  SQLite (default) tidak terpengaruh. Perbaikan butuh perubahan migrasi terpisah.

## Roadmap

- [ ] Deskripsi domain Laradger (ledger apa?)
- [ ] Dokumentasi endpoint API
- [ ] Seed & factory yang relevan
- [ ] CI + coverage

## Lisensi

MIT
