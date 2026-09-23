# HIMO — Facilities Service Request Management

![HIMO logo](public/images/branding/himo-logo.png)

**HIMO** comes from the Waray word meaning “do” or “make.” It reflects the institution’s commitment to turning service requests into action. As the need for digital processing grows, HIMO provides a central place to submit, track, and manage requests, helping offices respond more efficiently and keeping requesters informed from submission to completion.

HIMO is a Laravel and Filament application for managing facilities and maintenance requests from intake through completion. It replaces informal messages and calls with a controlled workflow for submitting, assigning, tracking, reviewing, and closing service work.

## What the application provides

- Service Category master data with active/inactive control
- Service Request intake with location, description, priority, and requested schedule
- Role-based assignment and work tracking
- Status workflow: **Submitted → Assigned → In Progress → For Confirmation → Completed**
- Return-for-correction and requester cancellation paths
- Server-side completion rule: a request cannot be completed without an assigned staff member and a non-empty completion note
- Scoped dashboards, search, filters, service accomplishment reporting, and status history
- Soft deletes and authenticated ownership/audit fields
- Optional operational tools including scheduling, staff availability, user administration, backups, and restore auditing

## User roles

| Role | Responsibilities |
|---|---|
| Requester | Submit and maintain owned requests, review completion, and cancel untouched submissions |
| Service Staff | View assigned work, start jobs, record work notes, and submit work for confirmation |
| Service Supervisor | Maintain categories, assign staff, review work, complete or return requests, and monitor operations |
| Super Admin | Manage users, permissions, backups, and all operational records |

Visibility is enforced on the server. Requesters see their own requests, Service Staff see current and historical assignments, and Supervisors see operational requests in their scope.

## Technology

- PHP 8.5 in the supplied Docker image (the locked dependency set requires PHP 8.4.1 or newer)
- Laravel 13
- Filament 5 with Livewire and Tailwind CSS
- PostgreSQL 16
- Redis 7 for cache and sessions
- Nginx and PHP-FPM
- PHPUnit for automated testing

## Quick start with Docker

Docker is the recommended development environment because it supplies the supported PHP runtime, PostgreSQL, Redis, Node, and database client tools.

Requirements: Docker Engine and Docker Compose.

```sh
docker compose up -d --build
```

The one-shot `setup` service installs PHP and JavaScript dependencies, creates the application key, runs migrations, seeds development data, and builds Vite assets before the application services start.

Open the application at [http://localhost/admin](http://localhost/admin). The default local Super Admin credentials are:

```text
Email:    super.admin@himo.test
Password: password
```

Other service URLs:

- Adminer: [http://localhost:8080](http://localhost:8080)
- Vite HMR: [http://localhost:5173](http://localhost:5173)

To stop the stack:

```sh
docker compose down
```

Add `-v` only when you intentionally want to remove the PostgreSQL, Redis, and Node volumes.

## Backups and PostgreSQL client version

The PHP image includes PostgreSQL 16 client tools (`pg_dump` and `pg_restore`) to match the `postgres:16` database service. PostgreSQL does not allow an older `pg_dump` client to back up a newer server.

After pulling this Dockerfile change, rebuild and recreate only the PHP service; this does not remove the database or its volumes:

```sh
docker compose build php
docker compose up -d --no-deps --force-recreate php
docker compose exec -T php pg_dump --version
```

The reported `pg_dump` major version must be 16 or newer while the database server remains PostgreSQL 16. If the database image major version changes, update the PHP image's PostgreSQL client major version at the same time.

## Focused Facilities demonstration

For a small, repeatable role-and-workflow demonstration, run the additive migration and demo seeders:

```sh
docker compose exec -T php php artisan migrate --force
docker compose exec -T php php artisan db:seed --class=FacilitiesDemoSeeder --force
```

Then use the dedicated local-only accounts:

| Role | Email | Password |
|---|---|---|
| Requester | `facilities-requester@example.test` | `facilities-demo-only` |
| Service Staff | `facilities-service_staff@example.test` | `facilities-demo-only` |
| Service Supervisor | `facilities-service_supervisor@example.test` | `facilities-demo-only` |

The focused seed creates two categories and six requests covering Submitted, Assigned, In Progress, For Confirmation, Completed, and return-for-correction scenarios. See the complete walkthrough in [`docs/facilities/demo.md`](docs/facilities/demo.md).

## Local setup without Docker

Use a PHP version compatible with the lock file (PHP 8.4.1 or newer), Composer, Node.js 22+, PostgreSQL, and Redis.

```sh
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
npm install
npm run build
php artisan serve
```

Configure the database and Redis values in `.env` before migrating. The Docker defaults are PostgreSQL database `hack_sims`, user `hack_sims`, and password `secret`.

## Testing and verification

Run the full test suite inside the PHP container:

```sh
docker compose exec -T php php artisan test --compact
```

Useful supporting checks:

```sh
docker compose exec -T php php artisan migrate:status
docker compose exec -T php npm run build
docker compose exec -T php php artisan route:list --path=admin
```

The test suite covers the workflow, completion rule, authorization boundaries, ownership visibility, soft deletes, dashboard/report behavior, status history, assignment conflicts, staff availability, scheduling, user management, and demo-seed idempotency.

## Documentation

- [`docs/facilities/demo.md`](docs/facilities/demo.md) — role-based walkthrough and demonstration data
- [`docs/facilities/diagrams.md`](docs/facilities/diagrams.md) — system context, ERD, process flow, and Level-0 data-flow diagrams
- [`docs/facilities/rbac-audit.md`](docs/facilities/rbac-audit.md) — role and authorization cross-reference
- [`docs/facilities/verification.md`](docs/facilities/verification.md) — implementation verification record
- [`09_facilities_service_requests_balanced.md`](09_facilities_service_requests_balanced.md) — case-study requirements
- [`Assessment.md`](Assessment.md) — QA assessment against the case-study requirements

## Data and operational notes

`DatabaseSeeder` creates development demonstration data and must not be used as a production data-loading strategy. Demo accounts use deliberately simple credentials and are for local environments only. Do not expose them on a public deployment.

Backups and restores are PostgreSQL-specific and are available to Super Admins. Test restore operations only against disposable data and confirm that the backup file and checksum are valid before restoring.

## License

This application is released under the MIT License. See [`LICENSE`](LICENSE) if present in the distribution.
