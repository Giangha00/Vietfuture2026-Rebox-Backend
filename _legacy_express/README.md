# Rebox Backend (Node.js + MongoDB)

Backend API for Rebox project using Express, MongoDB (Mongoose), JWT authentication, and hashed passwords.

## 1) Setup

```bash
npm install
cp .env.example .env
```

Update `.env`:

- `MONGODB_URI`: use your MongoDB Compass/local URI, for example `mongodb://127.0.0.1:27017/rebox_db`
- `JWT_SECRET`: any strong random secret
- `PUBLIC_BASE_URL`: backend public URL (default `http://localhost:5001`) — used for PayPal return URLs
- `FRONTEND_URL`: Next.js URL (default `http://localhost:3000`)
- `PAYPAL_MODE=sandbox`
- `PAYPAL_CLIENT_ID` / `PAYPAL_CLIENT_SECRET` from [PayPal Developer Dashboard](https://developer.paypal.com/dashboard/applications/sandbox)
- `PLATFORM_FEE_PERCENT=10` (escrow fee demo ledger)

## Marketplace order flow (Phases 1–4)

```text
pending_payment → paid (PayPal capture, escrow held)
→ seller_confirmed → pickup_assigned (shipper)
→ picked_up → out_for_delivery → delivered
→ completed (buyer confirm / auto) — escrow released
(+ cancelled / disputed branches)
```

### Key APIs

| Method | Path | Who |
|--------|------|-----|
| POST | `/api/orders` | Buyer — create (needs delivery address) |
| POST | `/api/orders/:id/pay` | Buyer — start PayPal Sandbox checkout |
| GET | `/api/payments/paypal/return` | PayPal redirect — capture + escrow hold |
| POST | `/api/orders/:id/seller-confirm` | Seller |
| POST | `/api/orders/:id/assign-shipper` | Admin or shipper self-claim |
| POST | `/api/orders/:id/shipper-status` | Shipper |
| POST | `/api/orders/:id/confirm-delivery` | Buyer — release escrow |
| POST | `/api/orders/:id/dispute` | Buyer |
| GET | `/api/orders/shipper/jobs` | Shipper |
| GET | `/api/orders/selling` | Seller |

Admin: `/admin/orders` — assign shipper, resolve disputes.

Create a shipper user in Admin → Users → role `shipper`, then open `/shipper` on the frontend.

## 2) Create database + sample data

```bash
npm run seed
```

This command creates collections and inserts sample docs:

- `users`
- `categories`
- `stations`
- `products`
- `offers`

## 3) Run server

```bash
npm run dev
```

Default server: `http://localhost:5001`

## 4) Auth & Authorize

### API (Bearer JWT)

| Method | Path | Auth |
|--------|------|------|
| POST | `/api/auth/register` | Public → sends email OTP |
| POST | `/api/auth/verify-email` | Public (`email` + `otp`) → returns JWT |
| POST | `/api/auth/resend-verification` | Public |
| POST | `/api/auth/login` | Public (requires verified email) → JWT |
| POST | `/api/auth/forgot-password` | Public |
| POST | `/api/auth/verify-reset-otp` | Public |
| POST | `/api/auth/reset-password` | Public |
| GET/PATCH | `/api/auth/me` | Bearer token |
| POST/PATCH/DELETE | `/api/products`, `/api/orders`, `/api/uploads`, `/api/notifications` | Bearer + verified email |

Header: `Authorization: Bearer <token>`

Middleware: `protect` (JWT) → `requireVerified` (email) → `authorize("admin")` (role, when needed).

### Admin panel (cookie)

- URL: `http://localhost:5001/admin`
- Login with `ADMIN_EMAIL` / `ADMIN_PASSWORD` from `.env` (auto-created on boot)
- Protected by `requireAdmin` (`role === "admin"`)

### Register body

```json
{
  "fullName": "Your Name",
  "email": "you@example.com",
  "phone": "0900000000",
  "password": "YourStrongPassword"
}
```

### Login body

```json
{
  "email": "you@example.com",
  "password": "YourStrongPassword"
}
```

## 5) Product APIs

- `GET /api/products` (public)
- `POST /api/products` (Bearer + verified email)

### Create product body

```json
{
  "title": "Product name",
  "description": "Details...",
  "price": 100,
  "condition": "Good",
  "images": ["https://..."],
  "category": "<categoryObjectId>",
  "station": "<stationObjectId>"
}
```
