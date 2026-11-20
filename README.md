# SwiftDesk — Symfony + MySQL + Docker QR Ticketing App

A clean, responsive ticketing web application built with **Symfony 7.4 (PHP 8.3)**, **Doctrine ORM**, **Symfony Mailer**, **MySQL 8.0**, and **Docker Compose**.

## Features

- **Unique QR Code per Ticket (Saved in MySQL)**: Every purchased ticket generates a cryptographically unique QR token persisted in MySQL (`tickets` table) and rendered as both inline SVG and downloadable PNG.
- **Email-Only Quick Checkout + Email Delivery**: Visitors enter their email address to purchase a 24-hour Festival QR Ticket, receive the QR code in their email inbox, and unlock a **See QR** button right in the main menu.
- **Customer Accounts & Purchase History (`/account`)**: Customers can sign up and log in to view active QR passes, live entrance scan statuses, and their complete purchase history (past guest tickets bought with the same email are automatically linked).
- **Single-Use Staff Entrance Scanner (`/scanner`)**: Staff unlock the scanner (`1234`), scan QR codes via live camera, uploaded QR `.png` image, or token input, and atomically mark each ticket as **USED** in MySQL — preventing any ticket from being used twice.
- **Admin Panel (`/admin`)**: Separate staff/admin console (`1234`) to filter/search all tickets, inspect QR validity, and update ticket statuses (`Open`, `In Progress`, `Resolved`).

---

## Quick Start with Docker

```bash
git clone https://github.com/marcmaxim20-hash/tickets.git
cd tickets
docker compose up -d --build
```

Open in your browser:
- **Main Site**: `http://localhost:8080`
- **Buy QR Ticket**: `http://localhost:8080/tickets/new`
- **Customer Account**: `http://localhost:8080/account`
- **Staff Entrance Scanner**: `http://localhost:8080/scanner` (Access Code: `1234`)
- **Admin Panel**: `http://localhost:8080/admin` (Access Code: `1234`)

### Optional: Real Gmail / SMTP Delivery

Create a `.env.local` file (ignored by Git) with your SMTP credentials:

```dotenv
SMTP_HOST=smtp.gmail.com
SMTP_PORT=587
SMTP_USER=your.email@gmail.com
SMTP_PASSWORD="your-16-char-google-app-password"
SMTP_FROM=your.email@gmail.com
```

---

## Local End-to-End (E2E) Tests

No local Node.js or PHP installation is required — both test suites run inside Docker.

### 1. Fast Backend E2E Tests (PHPUnit + Symfony BrowserKit)

Runs against an isolated `ticketing_db_test` MySQL database:

```bash
docker exec ticketing-web php vendor/bin/phpunit --testdox
```

### 2. Real-Browser E2E Tests (Playwright in Chromium)

Runs real browser clicks, seat map selection, drag/click seat arranging, and 1-seat-per-person verification against `http://web`:

```bash
docker compose --profile e2e run --rm --build e2e
```

After the first build, omit `--build` for instant runs:

```bash
docker compose --profile e2e run --rm e2e
```
