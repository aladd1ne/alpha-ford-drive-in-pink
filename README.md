# Drive in Pink

> Explore the Tunisian Jungle with us in a journey full of adrenaline. Untouched places.

A monolithic Symfony 6.4 web application that sells outdoor adventure experiences in Tunisia.  
Server-rendered pages, dark theme, burnt-orange accent, zero frontend framework.

---

## Stack

| Layer       | Technology                                    |
|-------------|-----------------------------------------------|
| Language    | PHP 8.4 (php:8.4-fpm Docker image)            |
| Framework   | Symfony 6.4 LTS                               |
| Templating  | Twig 3                                        |
| ORM         | Doctrine ORM 3                                |
| Database    | MySQL 8.0                                     |
| Forms       | Symfony Form + Validator                      |
| Admin       | EasyAdmin 4 (dark-themed)                     |
| Charts      | Chart.js 4 (CDN, no build step)              |
| Styling     | Plain CSS (Inter font via Google)             |
| JS          | Vanilla JS (price calculator + Chart.js only) |

---

## Pages

| Route                          | Name                   | Description                              |
|-------------------------------|------------------------|------------------------------------------|
| `/`                            | `home`                 | 4-panel landing (2×2 experience grid)   |
| `/experience/{slug}`           | `experience_show`      | Detail page: gallery, highlights, price |
| `/book/{slug}`                 | `booking_form`         | Booking form + payment instructions     |
| `/booking/confirmation/{id}`   | `booking_confirmation` | Thank-you page with booking reference   |

### Admin routes (`/admin` — requires `ROLE_ADMIN`)

| Route                        | Description                                      |
|------------------------------|--------------------------------------------------|
| `/admin/login`               | Login page (public)                              |
| `/admin`                     | Statistics dashboard (bookings, revenue, charts) |
| `/admin/category`            | Experiences CRUD (create, edit, archive)         |
| `/admin/booking`             | Bookings list with filters and status actions    |
| `/admin/customers`           | Customer view grouped by e-mail                  |
| `/admin/bookings/export.csv` | Download all bookings as CSV                     |

---

## Setup

### Prerequisites

- Docker + Docker Compose

### 1 — Clone & configure environment

```bash
cp .env .env.local
# Edit .env.local — DATABASE_URL is already set for the Docker MySQL service:
# DATABASE_URL="mysql://driveinpink_user:driveinpink_password@db:3306/driveinpink?serverVersion=8.0.32&charset=utf8mb4"
```

### 2 — Start Docker services

```bash
docker-compose up -d --build
# Services: php (PHP 8.4, port 9000), nginx (port 3200), db (MySQL 8), phpmyadmin (port auto)
```

The Compose project is named `drive-in-pink`, so containers and volumes are prefixed with `drive-in-pink-`.
The MySQL database is `driveinpink` (user `driveinpink_user` / password `driveinpink_password`).

The application is available at **http://localhost:3200**

### 3 — Install dependencies

```bash
docker-compose exec php composer install
```

### 4 — Run migrations

```bash
docker-compose exec php bin/console doctrine:migrations:migrate --no-interaction
```

### 5 — Load fixtures (sample data)

```bash
docker-compose exec php bin/console doctrine:fixtures:load --no-interaction
```

This seeds 4 experience categories:

| Slug           | Name           | Base price |
|----------------|----------------|------------|
| `sup-day`      | SUP Day        | 80 TND     |
| `beach-day`    | Beach Day      | 60 TND     |
| `jungle-trek`  | Jungle Trek    | 90 TND     |
| `camping-night`| Camping Night  | 120 TND    |

Each comes with images, highlight specs, and 3–4 optional add-ons.  
Fixtures also seed **24 sample bookings** spread across the last 12 months and one admin user:

| E-mail              | Password      |
|---------------------|---------------|
| `admin@andiamo.tn`  | `andiamo2026` |

### 6 — Access the admin panel

Open **http://localhost:3200/admin/login** and log in with the credentials above.

### 7 — Create a new admin user (optional)

```bash
docker-compose exec php php bin/console app:create-admin your@email.com yourpassword
```

### 8 — Clear cache (if needed)

```bash
docker-compose exec php bin/console cache:clear
```

---

## Configuration

All business parameters live in `config/services.yaml` and are injected as Twig globals:

| Parameter            | Default              | Purpose                        |
|----------------------|----------------------|--------------------------------|
| `app.deposit_amount` | `50`                 | Booking deposit in TND         |
| `app.contact_phone`  | `+216 71 000 000`   | Contact number shown everywhere|
| `app.bank_name`      | `Banque de Tunisie`  | Bank name for transfer details |
| `app.bank_rib`       | `08 006 0123456789 12` | RIB displayed on booking pages |
| `app.bank_swift`     | `BTUNTNTX`           | SWIFT/BIC code                 |

The solidarity fund total shown on the home page comes from the `SOLIDARITY_FUND_AMOUNT` env var (default `0` in `.env`).
Set it in `.env.local` (e.g. `SOLIDARITY_FUND_AMOUNT=12450`) and clear the cache.

Override any of these in `.env.local` via Symfony's parameter system, or edit `config/services.yaml` directly.

---

## Data Model

```
Category          1 ──< EventImage
Category          1 ──< Highlight
Category          1 ──< Extra
Category          1 ──< Booking
```

**Booking** stores `selectedExtras` as a JSON array:
```json
[{"id": 1, "name": "Lunch box", "price": "15.00"}]
```

Booking references are auto-generated on persist (e.g. `AND-A3F9C2D1`).

---

## Development

### Run a shell inside the PHP container

```bash
docker-compose exec php bash
# or via Makefile:
make dcexec
```

### View logs

```bash
make dclogs
```

### PhpMyAdmin

PhpMyAdmin runs on an auto-assigned port. Find it with:

```bash
docker-compose ps
```

---

## Images

Fixtures use Unsplash image URLs for placeholder images.  
For production, replace the `heroImage`, `panelImage`, and `imagePath` values in the fixture or via a future admin interface with your own hosted images.

---

## Customisation

- **Add a new experience category**: add a block to the `$data` array in `src/DataFixtures/AppFixtures.php` and re-load fixtures.
- **Change accent colour**: edit `--accent` in `public/css/app.css`.
- **Booking flow**: modify `BookingController::form()` — all extra/price logic is in one method.
