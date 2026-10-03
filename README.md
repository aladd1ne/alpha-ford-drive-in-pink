# Drive in Pink

> Test drive solidaire — en octobre, chaque essai devient un geste de soutien.

Alpha Ford's Octobre Rose campaign site. For every test drive, 10 DT from the client + 20 DT from Alpha Ford
(30 DT) go to a solidarity fund for medical monitors at the Institut Salah Azaïez.

A Symfony 6.4 monolith: server-rendered Twig pages, plain CSS (light theme, rose accent), a few lines of vanilla JS.

---

## Stack

| Layer      | Technology                                        |
|------------|---------------------------------------------------|
| Language   | PHP 8.4 (php:8.4-fpm Docker image)                |
| Framework  | Symfony 6.4 LTS                                   |
| Templating | Twig 3                                            |
| ORM        | Doctrine ORM 3 + Migrations                       |
| Database   | MySQL 8.0 (SQLite for tests)                      |
| Forms      | Symfony Form + Validator                          |
| Admin      | EasyAdmin 4                                       |
| Styling    | Plain CSS (`public/css/front.css`), Onest + Inter |
| JS         | Vanilla (`public/js/reservation.js`)              |
| Tests      | PHPUnit 11                                        |

---

## Pages

| Route                                     | Name                       | Description                                         |
|-------------------------------------------|----------------------------|-----------------------------------------------------|
| `/`                                       | `home`                     | Campaign page: hero, solidarity fund, events        |
| `/reservation`                            | `reservation`              | Choice of the three booking journeys                |
| `/reservation/{experience}`               | `reservation_form`         | Booking form (`everest-ranger`, `territory`, `octobre`) |
| `/reservation/{experience}/confirmation`  | `reservation_confirmation` | Confirmation message (after a submission only)      |

### Back-office (`/admin` — requires `ROLE_COMMERCIAL`; `ROLE_ADMIN` inherits it)

| Route                                  | Role              | Description                                                       |
|----------------------------------------|-------------------|-------------------------------------------------------------------|
| `/admin/login`                         | public            | Login page                                                        |
| `/admin`                               | `ROLE_COMMERCIAL` | Dashboard: solidarity fund, test drives to validate today         |
| `/admin/test-drives`                   | `ROLE_COMMERCIAL` | Today's registrations, "Test drive effectué" action               |
| `/admin/test-drives/{id}/validate`     | `ROLE_COMMERCIAL` | POST + CSRF: confirms the test drive, credits the fund (once)     |
| `/admin/reservation`                   | `ROLE_ADMIN`      | All reservations (filters, search) + "Test drive effectué" action |
| `/admin/commerciaux`                   | `ROLE_ADMIN`      | Create / edit / delete commercial accounts (`ROLE_COMMERCIAL`)    |

### Test drive validation & solidarity fund

- A booking only records the reservation (`reservation.status`); it never credits the fund.
- When a commercial (or admin) confirms the drive, `reservation.status` goes from `pending` to
  `confirmed`, `reservation.test_drive_status` becomes `completed`
  (with `test_drive_completed_at` / `test_drive_validated_by`) and one `fund_contribution` row is
  written: 10 DT client + 20 DT Alpha Ford = 30 DT, with the date and the commercial's e-mail.
- Idempotent: a reservation already validated is left unchanged, and the unique
  `fund_contribution.reservation_id` index rejects any second contribution.
- Public fund total = `SOLIDARITY_FUND_AMOUNT` (opening amount) + sum of the contributions.

---

## Reservations

| Journey                  | Dates               | Fields                                                    |
|--------------------------|---------------------|-----------------------------------------------------------|
| Everest & Ranger         | 9 & 10 October      | name, phone, e-mail, date, vehicle (5 models), time slot  |
| Territory                | 23 & 24 October     | name, phone, e-mail, date, time slot                      |
| Other days of October    | rest of the month   | name, phone, e-mail, desired date, desired vehicle        |

- Slots run from 09h00 every 45 min; Friday's last slot ends at 16h15, Saturday's at 14h00.
- Availability is tracked per **date + vehicle + slot**: a full slot for one vehicle never blocks another.
- Each booking holds a seat (1..capacity); a unique index on experience + date + vehicle + slot + seat
  makes the database reject concurrent double bookings.
- "Other days" requests have no slot: an Alpha Ford adviser calls the client back.
- An experience without any free slot shows a disabled **Complet** button.

Dates, closing times, slots and capacity are configured in `config/packages/reservation.yaml`.

---

## Setup

### Prerequisites

- Docker Desktop (with WSL integration on Windows) and Docker Compose

### 1 — Configure the environment

`.env` already points `DATABASE_URL` to the Docker MySQL service. Put local overrides in `.env.local`.

### 2 — Start Docker services

```bash
make dcupd
# Services: php (PHP 8.4, port 9000), nginx (port 3200), db (MySQL 8), phpmyadmin (port auto), mailhog (8025)
```

The Compose project is named `drive-in-pink`. The MySQL database is `driveinpink`
(user `driveinpink_user` / password `driveinpink_password`).

The application is available at **http://localhost:3200**

### 3 — Install dependencies

```bash
bin/docker-compose exec php composer install
```

### 4 — Run migrations

```bash
bin/docker-compose exec php bin/console doctrine:migrations:migrate --no-interaction
```

### 5 — Load fixtures (development)

```bash
bin/docker-compose exec php bin/console doctrine:fixtures:load --no-interaction
```

This creates development back-office accounts:

| E-mail                      | Password    | Role              |
|-----------------------------|-------------|-------------------|
| `admin@driveinpink.tn`      | `123456789` | `ROLE_ADMIN`      |
| `commercial@driveinpink.tn` | `123456789` | `ROLE_COMMERCIAL` |

### 6 — Back-office

Open **http://localhost:3200/admin/login** and log in.

### 7 — Create an admin or commercial user

```bash
bin/docker-compose exec php bin/console app:create-admin
bin/docker-compose exec php bin/console app:create-admin --commercial   # test drive validation only
# Commercial accounts can also be created by an admin in the back-office: Utilisateurs → Commerciaux.
# Prompts for the e-mail (domain autocompletes after "@") and the password (hidden, asked twice).
```

---

## Configuration

| Setting                    | Where                              | Purpose                                         |
|----------------------------|------------------------------------|-------------------------------------------------|
| `SOLIDARITY_FUND_AMOUNT`   | `.env` / `.env.local`              | Fund total shown on the home page (DT)          |
| `ASSETS_VERSION`           | `.env`                             | Cache-busting suffix for CSS/JS — bump on change |
| `app.contact_phone`        | `config/services.yaml`             | Phone number in the footer                      |
| `app.reservation.*`        | `config/packages/reservation.yaml` | Event dates, closing times, slots, capacity     |

Clear the cache after changing them: `bin/docker-compose exec php bin/console cache:clear`.

---

## Data model

- `Reservation` — experience, contact details, date, vehicle, slot, seat, status (`pending` / `confirmed` /
  `cancelled`), creation time. Commercial validation is handled in the back-office (next step).
- `AdminUser` — back-office accounts.

---

## Development

```bash
make dcexec                                    # shell inside the PHP container
make dclogs                                    # container logs
bin/docker-compose exec php bin/phpunit        # tests (SQLite, clock frozen on 1 Oct 2026)
bin/docker-compose ps                          # phpMyAdmin port
```
