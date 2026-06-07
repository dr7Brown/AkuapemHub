# AkuapemHub

A mobile-first community hub for local services and events in Africa.

## Features

- Email/password authentication
- MySQL data storage
- Browse and search listings by category and location
- Create new community listings
- Lightweight mobile-first frontend

## Setup

1. Copy `.env.example` to `.env` and update values.
2. Create the MySQL database and tables using `schema.sql`.
3. Install dependencies:
   ```bash
   npm install
   ```
4. Start the server:
   ```bash
   npm start
   ```
5. Open `http://localhost:4000` in the browser.

## API Endpoints

- `POST /api/auth/register`
- `POST /api/auth/login`
- `GET /api/categories`
- `GET /api/listings`
- `POST /api/listings`

## Notes

- The app is designed for low bandwidth and simple devices.
- Static frontend assets are served from `backend/public`.
