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

Default server: `http://localhost:5000`

## 4) Auth APIs

- `POST /api/auth/register`
- `POST /api/auth/login`
- `GET /api/auth/me` (Bearer token required)

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
- `POST /api/products` (Bearer token required)

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
