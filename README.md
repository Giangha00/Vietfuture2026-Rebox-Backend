# ReBox Backend (Laravel + Filament)

Express/MongoDB backend has been replaced by **Laravel 13 + Filament 4 + MySQL**.

Legacy Node code is kept under [`_legacy_express/`](_legacy_express/) for reference only.

## Requirements

- PHP 8.2+
- Composer
- MySQL via **XAMPP** (database name: `rebox`)
- Next.js frontend still expects API at `http://localhost:5001`

## Setup (XAMPP)

1. Start **Apache** + **MySQL** in XAMPP.
2. Open phpMyAdmin (`http://localhost/phpmyadmin`) and confirm database `rebox` exists (or create it).
3. Copy env and install:

```bash
cp .env.example .env
php artisan key:generate
composer install
php artisan migrate --seed
php artisan storage:link
```

4. Run API + Filament on port **5001**:

```bash
php artisan serve --host=127.0.0.1 --port=5001
```

Share with teammates on the same Wi‑Fi (bind all interfaces, then send the **LAN IP**, not `localhost`):

```bash
composer run serve:lan
# frontend: npm run dev:lan
# print URLs: ipconfig getifaddr en0
```

## URLs

| Surface | URL |
|---------|-----|
| API health | http://localhost:5001/api/health |
| Filament admin | http://localhost:5001/admin |
| Uploads | http://localhost:5001/storage/uploads/... |

Default admin (seeded):

- Email: `admin@rebox.com`
- Password: `Admin@123`

Demo accounts (from full legacy seed):

- Users: `marcus@rebox.com`, `buyer@rebox.com`, … / `Rebox@123`
- Shipper: `shipper@rebox.com` / `Rebox@123`

Seed includes **20 users**, **8 categories**, **6 stations**, **100 products**, **3 sample offers**.

```bash
php artisan db:seed
```

## API compatibility

JSON routes mirror the previous Express API under `/api/*` (auth, products, offers, orders, PayPal, notifications, uploads). JWT uses `Authorization: Bearer <token>` with payload `{ sub: userId }`. Responses include both `id` and `_id` for frontend compatibility.

## Useful commands

```bash
php artisan migrate:fresh --seed
php artisan filament:optimize
```
