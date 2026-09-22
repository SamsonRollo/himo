# Facilities implementation and verification record

Verified on 2026-09-22 using the existing Docker runtime (PHP 8.5.10, PostgreSQL 16). This is the core implementation/change record; no PDCA deliverable is included.

## Final results

| Check | Evidence |
|---|---|
| Baseline PHPUnit | 2 tests, 2 assertions passed before implementation |
| Final full PHPUnit | 24 tests, 185 assertions passed |
| Pint | All 35 affected PHP files passed |
| Database | Four existing plus three new migrations applied; no pending migrations |
| PostgreSQL completion constraint | Missing assignment, null note, and whitespace-only note each rejected with SQLSTATE 23514; all probe transactions rolled back |
| Demo metrics in PostgreSQL | Requester: Open 5, Assigned 1, In-progress 2, Completed 1 |
| Demo idempotency | Test seeds twice, checks six requests and unchanged account password hashes |
| Filament workflow | Livewire test submits, assigns, starts, sends for confirmation and completes; five history records |
| Alternate path | Return requires reason, retains assignment, clears note, and permits correction/resubmission |
| Visibility | Other requesters' records absent from listings/report; direct record URL returns 404; direct history component access returns 403 |
| Category maintenance | Filament creation, update, soft deletion and unauthorized creation checked |
| Existing Shield integration | Saving requester role through Shield preserves its facilities permission grants |
| Login | Demo login tested through existing Filament login; live nginx login page HTTP 200 |
| Routes | 15 admin routes registered, including existing Shield/login routes and new facilities routes |
| Build | Existing `npm run build` passed with Vite 8.3.0 |
| Containers | php, nginx, vite, adminer running; PostgreSQL and Redis healthy |
| Repository | `git diff --check` passed; dependency manifests/locks, Docker architecture and PHPUnit configuration unchanged |

No unresolved implementation failures remain. Initial local PHP/Node and Docker availability limitations were resolved by using the existing running containers, without changing stack versions. No interactive browser session or Mermaid rendering tool was used; interface behavior was exercised through Livewire/HTTP tests, and diagram source was checked against the implemented schema/workflow.

## Atomic slices and exact file inventory

All listed application files were added except `config/filament-shield.php`, which was narrowly modified. Files appearing in multiple phases were extended as that phase's responsibility required. The pre-existing untracked specification was not edited.

### Phase 1 — Data foundation

- `app/Enums/ServiceRequestStatus.php`
- `app/Models/Concerns/TracksAuthenticatedOwnership.php`
- `app/Models/ServiceCategory.php`
- `app/Models/ServiceRequest.php`
- `app/Models/ServiceRequestStatusHistory.php`
- `database/factories/ServiceCategoryFactory.php`
- `database/factories/ServiceRequestFactory.php`
- `database/migrations/2026_09_22_070000_create_service_categories_table.php`
- `database/migrations/2026_09_22_070001_create_service_requests_table.php`
- `database/migrations/2026_09_22_070002_create_service_request_status_histories_table.php`
- `tests/Feature/Facilities/FacilitiesTestCase.php`
- `tests/Feature/Facilities/DataFoundationTest.php`

Three additive migrations applied successfully. Targeted verification: 3 tests, 14 assertions. No existing tables or records changed.

### Phase 2 — Authorization

- `app/Policies/ServiceCategoryPolicy.php`
- `app/Policies/ServiceRequestPolicy.php`
- `app/Models/ServiceRequest.php`
- `database/seeders/FacilitiesRoleSeeder.php`
- `config/filament-shield.php`
- `tests/Feature/Facilities/AuthorizationTest.php`

Added domain role/permission grants without removing existing roles or grants. New policies combine permission checks with role and ownership/assignment rules. Tests then passed: 5 tests, 32 assertions. Directory ownership for `app/Policies` and file ownership for the Docker-generated Shield configuration required scoped corrections; no existing policy contents were changed. An interrupted patch left duplicate code, which was removed before this phase passed.

### Phase 3 — Workflow

- `app/Services/ServiceRequestWorkflow.php`
- `app/Models/ServiceRequest.php`
- `tests/Feature/Facilities/FacilitiesTestCase.php`
- `tests/Feature/Facilities/DataFoundationTest.php`
- `tests/Feature/Facilities/AuthorizationTest.php`
- `tests/Feature/Facilities/WorkflowTest.php`

Added authorized locked transitions, transactional history and direct model-write safeguards. No schema changes. Tests passed: 10 tests, 55 assertions after correcting immutable-creator validation order.

### Phase 4 — Interface

- `app/Filament/Resources/ServiceCategories/ServiceCategoryResource.php`
- `app/Filament/Resources/ServiceCategories/Pages/ListServiceCategories.php`
- `app/Filament/Resources/ServiceCategories/Pages/CreateServiceCategory.php`
- `app/Filament/Resources/ServiceCategories/Pages/EditServiceCategory.php`
- `app/Filament/Resources/ServiceRequests/ServiceRequestResource.php`
- `app/Filament/Resources/ServiceRequests/Pages/ListServiceRequests.php`
- `app/Filament/Resources/ServiceRequests/Pages/CreateServiceRequest.php`
- `app/Filament/Resources/ServiceRequests/Pages/EditServiceRequest.php`
- `app/Filament/Resources/ServiceRequests/Pages/ViewServiceRequest.php`
- `tests/Feature/Facilities/InterfaceTest.php`

Added version-matched resources/forms/tables/actions and status display. No schema changes. Interface checks passed: 2 tests, 33 assertions after correcting the Filament action-hook signature.

### Phase 5 — Metrics, report, history and demonstration data

- `app/Filament/Widgets/ServiceRequestMetrics.php`
- `app/Filament/Pages/ServiceAccomplishmentReport.php`
- `resources/views/filament/pages/service-accomplishment-report.blade.php`
- `app/Filament/Resources/ServiceRequests/RelationManagers/StatusHistoriesRelationManager.php`
- `app/Filament/Resources/ServiceRequests/ServiceRequestResource.php`
- `database/seeders/FacilitiesDemoSeeder.php`
- `tests/Feature/Facilities/ReportingAndDemoTest.php`

Seeded three isolated demonstration users, two categories and six requests with workflow-generated history. No existing users overwritten; no schema changes. Facilities tests then passed: 13 tests, 112 assertions, including direct history-access denial.

### Phase 6 — Required diagrams and demonstration guide

- `docs/facilities/diagrams.md`
- `docs/facilities/demo.md`

Four Mermaid diagrams based on the final migrations and workflow, plus setup and demonstration steps. No database changes.

### Phase 7 — Final verification

- `tests/Feature/Facilities/BoundaryTest.php`
- `app/Filament/Resources/ServiceRequests/Pages/ViewServiceRequest.php` (protected framework hook)
- `config/filament-shield.php` (expose domain grants through existing custom-permission UI)
- `docs/facilities/verification.md`

Added independent completion-rule cases, authorization boundaries, category CRUD, history protection, ordinary-edit history deduplication, inactive category validation, login, and Shield role-editor regression coverage. Final totals: 24 tests, 185 assertions. A database probe initially used a mistyped demo description; the corrected probe passed without persistent data changes.

## Commands executed

Discovery used `git status`, `git diff`, `rg`, file reads, `php -v`, `php -m`, `composer -V`, `node -v`, `npm -v`, `docker --version`, and `docker compose ps`. Neither `.env` contents nor secret values were printed.

Application and verification commands (run from the repository root):

```sh
docker compose exec -T php php -m
docker compose exec -T php php artisan migrate:status
docker compose exec -T php php artisan migrate --force
docker compose exec -T php php artisan db:seed --class=FacilitiesRoleSeeder --force
docker compose exec -T php php artisan db:seed --class=FacilitiesDemoSeeder --force
docker compose exec -T php php vendor/bin/phpunit --do-not-cache-result tests/Feature/Facilities/DataFoundationTest.php
docker compose exec -T php php vendor/bin/phpunit --do-not-cache-result tests/Feature/Facilities/InterfaceTest.php
docker compose exec -T php php vendor/bin/phpunit --do-not-cache-result tests/Feature/Facilities/ReportingAndDemoTest.php
docker compose exec -T php php vendor/bin/phpunit --do-not-cache-result tests/Feature/Facilities/BoundaryTest.php
docker compose exec -T php php vendor/bin/phpunit --do-not-cache-result tests/Feature/Facilities
docker compose exec -T php php vendor/bin/phpunit --do-not-cache-result
docker compose exec -T php php artisan route:list --path=admin
docker compose exec -T php npm run build
docker compose ps --format '{{.Service}} {{.State}} {{.Health}}'
docker compose exec -T php curl -s -o /dev/null -w 'Login HTTP status: %{http_code}\n' http://nginx/admin/login
git diff --check
git diff -- composer.json composer.lock package.json package-lock.json docker-compose.yml .docker phpunit.xml
```

Pint was run on the changed PHP files during each slice, then verified with this final exact command:

```sh
docker compose exec -T php php vendor/bin/pint --test app/Enums app/Models/Concerns app/Models/ServiceCategory.php app/Models/ServiceRequest.php app/Models/ServiceRequestStatusHistory.php app/Policies/ServiceCategoryPolicy.php app/Policies/ServiceRequestPolicy.php app/Services app/Filament database/factories/ServiceCategoryFactory.php database/factories/ServiceRequestFactory.php database/migrations/2026_09_22_070000_create_service_categories_table.php database/migrations/2026_09_22_070001_create_service_requests_table.php database/migrations/2026_09_22_070002_create_service_request_status_histories_table.php database/seeders/FacilitiesRoleSeeder.php database/seeders/FacilitiesDemoSeeder.php config/filament-shield.php tests/Feature/Facilities
```

The PostgreSQL probe bootstrapped the application using `docker compose exec -T php php -r`, located the isolated Submitted demo request, and attempted three invalid completion updates inside separate transactions. Each returned SQLSTATE 23514; `rollBack()` ran in `finally` for every attempt. It then read the four scoped metrics. No record change persisted from the probe.

No package installations, destructive migrations, Git commits or pushes were performed. The only existing tracked file modified is the Shield configuration. Core work is complete; PDCA and optional/bonus work remain subject to explicit approval.
