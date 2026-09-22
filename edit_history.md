# Edit History

## 2026-09-22 — Facilities RBAC audit, user management, and backup/restore

### Audit finding and fix

Audited every role (Requester, Service Staff, Service Supervisor, Super Admin) against every Facilities view/route/action and against `09_facilities_service_requests_balanced.md`. Found one gap: `FacilitiesRoleSeeder`'s shared `$common` permission set granted `ViewAny:ServiceCategory`/`View:ServiceCategory` to Requester and Service Staff, letting them browse (read-only) a resource meant to be invisible to them. Fixed by moving those permissions into a `service_supervisor`-only set; `super_admin` is unaffected since it already holds every permission explicitly. The full cross-reference is in `docs/facilities/rbac-audit.md`.

### Requester UI isolation

Relabeled the generic Service Request "Delete" action to "Cancel request" (`ServiceRequestResource::cancelAction()`), same underlying authorization (`ServiceRequestPolicy::delete`: owner, Submitted only, or admin). No new "Reopen from Completed" transition was added — confirmed with the requester that Completed should stay terminal, preserving the already-judged completion business rule; the existing Return-for-correction and Cancel paths serve the equivalent alternate-path role.

### Service Staff task history

Added an append-only `service_request_assignments` log (`ServiceRequestAssignment` model), written by `ServiceRequestWorkflow::assign()` in the same transaction as the status change, so a staff member's task history doesn't depend solely on the current, mutable `assigned_to` column. `ServiceRequest::scopeVisibleTo()` now also matches staff via this log. `ListServiceRequests` gained Active/History tabs (Filament's `getTabs()`), splitting by completion status.

### Super Admin user management

Added `status`/`deactivated_at`/`deactivated_by` plus `softDeletes()` to `users` (migration `2026_09_22_080100_...`). `User::booted()` blocks `forceDelete()` outright — hard deletion is never possible, matching the existing `TracksAuthenticatedOwnership` pattern used by `ServiceCategory`/`ServiceRequest`. The actual non-destructive "delete" the case study asks for is a status toggle: `UserResource`'s record action deactivates/reactivates (`status` + `deactivated_at`/`deactivated_by`), never calling Eloquent's real delete, so `created_by`/`assigned_to` and all history stay intact. `canAccessPanel()` now also requires an active status. `UserPolicy` restricts all of this to `super_admin`, and additionally blocks self-deactivation and reducing active `super_admin` accounts to zero.

CSV import/export uses Filament's built-in `filament/actions` Importer/Exporter (already vendored, no new Composer dependency — only its three migrations needed copying into `database/migrations`, matching how the package expects to be installed). `UserImporter` matches existing accounts by email (create vs. update, controlled by an "update existing" checkbox), restricts the role column to the three domain roles, and explicitly rejects any row naming an existing `super_admin` account or the `super_admin` role, closing a CSV-based privilege-escalation path. Imported accounts get a random unusable password pending a normal password reset, since CSVs never carry credentials.

### Server-side backup and restore

`BackupService` shells out to `pg_dump`/`pg_restore` (already installed in `.docker/php/Dockerfile` for `pg_isready`, so no image change was needed) rather than adding a backup package. Backups are written to the existing private `local` disk (`storage/app/private/backups`, never web-served) with a sha256 checksum recorded alongside. Restore re-verifies that checksum before touching anything, runs `pg_restore --clean --single-transaction` so the whole restore commits or rolls back atomically, and wraps the window in `artisan down`/`up`. Both actions always write an audit row (`SystemBackup`/`SystemRestore`), including on failure. The `SystemBackups` Filament page requires typing the exact backup filename to confirm a restore, on top of the standard confirmation dialog. Gated behind a new `Manage:SystemBackup` permission held only by `super_admin`.

### Verification and a known environment gap

This phase's sandbox had no PDO driver at all (`php -m` showed neither `pdo_sqlite` nor `pdo_pgsql`) and no Docker/socket access, so nothing that touches a database could be run or exercised here — no `migrate`, no PHPUnit, no live click-through. Every change was written to match this repo's existing tested patterns exactly (soft-delete/ownership guard, transactional workflow writes, Filament policy-driven authorization, append-only audit models) and `php -l` was run on every new/changed file. **The user must run `docker compose exec -T php php artisan migrate --force` and the full PHPUnit suite in their existing stack before trusting this**, especially the backup/restore flow, which was deliberately left out of the automated suite (a real `pg_dump`/`pg_restore` round trip risks `pg_restore --clean` wiping a real shared dev database if run automatically) and instead needs a manual click-through per `docs/facilities/demo.md`.

## 2026-09-22 — Facilities and Service Request Management MVP

### Data foundation

Added three additive migrations:

- `service_categories`: category details, active state, authenticated creator/updater fields, timestamps, and soft deletes.
- `service_requests`: generated request number, category, free-text location, description, five statuses, assignment, completion fields, ownership fields, timestamps, and soft deletes.
- `service_request_status_histories`: append-only workflow audit records.

Added `ServiceRequestStatus`, Eloquent models, factories, ownership tracking, and PostgreSQL completion validation. The database constraint rejects Completed requests when either `assigned_to` or a non-whitespace `completion_note` is absent.

### Authorization and visibility

Added `service_staff` and `service_supervisor` roles without removing the repository's existing roles. Added facilities permissions through `FacilitiesRoleSeeder` and updated Shield configuration so the existing role editor can manage those permissions.

Added policies and a `visibleTo()` query scope:

- Requesters see their own requests and can edit or soft-delete only Submitted requests.
- Service Staff see requests assigned to them and may perform work transitions.
- Service Supervisors see all requests, manage categories, assign staff, and confirm or return requests.
- Existing super admins retain access while workflow checks remain enforced.

### Workflow and audit trail

Added `ServiceRequestWorkflow` as the centralized, transactional transition layer. It locks the request before authorizing and changing it, then saves matching history in the same transaction.

Implemented:

- Submit request
- Assign Service Staff
- Start work
- Update work note
- Send for confirmation
- Complete request
- Return for correction with a required reason

Direct edits to workflow fields are rejected outside the workflow layer. History records cannot be edited or deleted. Returning a request retains its assignment, clears the previous completion note, and requires staff to provide a new note before resubmission.

### Filament interface

Added Service Category and Service Request resources with create, edit, view, search, filters, status badges, authorization-aware workflow actions, and soft-delete actions.

Added a read-only Status History relation manager. Its component, source query, and parent record access all enforce request visibility.

Added the Service Accomplishment Report page and Service Request Metrics widget. The report filters by category/status and the dashboard displays open, assigned, in-progress, and completed counts using the signed-in user's visibility scope.

### Demonstration data and documentation

Added `FacilitiesDemoSeeder`, which creates dedicated non-production facilities users only when absent and does not overwrite existing accounts. It seeds two categories and six requests covering Submitted, Assigned, In Progress, For Confirmation, Completed, and Returned for Correction scenarios.

Added:

- `docs/facilities/demo.md` — environment setup, demo steps, accounts, and expected metrics.
- `docs/facilities/diagrams.md` — System Context Diagram, ERD, Process Flow Diagram, and Level 0 DFD.
- `docs/facilities/verification.md` — phase inventory, commands, evidence, and verification outcomes.

### Verification and corrections during implementation

- Baseline test suite passed before changes: 2 tests, 2 assertions.
- Corrected temporary duplicate code created by an interrupted patch before Phase 2 passed.
- Corrected immutable-creator validation order so attempts to change `created_by` receive the intended validation failure.
- Corrected the View Record action hook to match the installed Filament 5 method signature.
- Added direct history-component access checks after identifying that tab visibility alone was insufficient protection.
- Added Shield custom-permission coverage after identifying that excluded resource policies still needed their permissions available in the existing role editor.
- Corrected a rollback-only PostgreSQL probe query containing a mistyped demo description; the corrected probe rejected all three invalid completion cases and left no persisted changes.

Final verification passed:

- PHPUnit: 24 tests, 185 assertions.
- Pint: 35 affected PHP files.
- Vite production build.
- Migration status: all existing and new migrations applied.
- Live login route: HTTP 200.
- Containers: PHP, nginx, Vite, and Adminer running; PostgreSQL and Redis healthy.

No dependencies, framework versions, Docker architecture, authentication package, authorization package, test framework, or deployment configuration were changed. No PDCA or optional/bonus features were implemented.
