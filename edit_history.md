# Edit History

## 2026-09-22 — Priority, scheduling, staff calendar/availability, and default Requester role

### Audit and two decisions confirmed before writing code

Inspected roles/permissions, the User/ServiceRequest/ServiceRequestAssignment schema, the admin panel config, and existing tests before touching anything (this phase's own rule 8: stop and explain conflicts first). Found: no public registration route (the only user-creation paths are the Super-Admin-only `UserResource`, `UserImporter`, and seeders); `UserResource`'s role field was hard-capped to one role (`maxItems(1)`) and `UserImporter::afterSave()` used `syncRoles()` (replaces, not adds) — both a direct conflict with "a user may hold Requester together with another role," fixed as part of this phase; no staff-profile model or scheduling model of any kind; no generic audit-log table (only `ServiceRequestStatusHistory`, which is request-specific); no calendar package installed anywhere.

Two decisions needed explicit confirmation rather than a guess: which calendar approach, and how to record schedule/staff-status history given no generic audit table exists. Asked; user picked `saade/filament-fullcalendar` and a new lightweight audit table. The calendar package turned out not to be installable — `composer require --dry-run` showed its latest release (v3.2.4) caps at `filament/filament ^3.0`, and this repo runs v5.8.4 — so, per the pre-agreed fallback, built the calendar with FullCalendar.js loaded from a CDN inside a custom Filament page instead, no new Composer dependency.

### Service priority

`ServiceRequestPriority` enum (`low`/`normal`/`high`/`urgent`/`critical`), added as a new column with a DB-level default of `normal`, which backfilled the existing 700 seeded requests automatically on migration — no separate backfill step needed. Every display surface (form, infolist, table, filter, calendar event) pairs the priority's color with an icon and the label text itself, since the spec explicitly required not relying on color alone.

### Schedule and the new generic audit table

Added `activity_logs` (`subject_type`/`subject_id` polymorphic morph, `action`, `from_value`/`to_value`, `remarks`, `changed_by`, append-only — mirrors `ServiceRequestStatusHistory`'s shape) per the earlier decision. Added `needed_start_at`/`needed_end_at` (the requester's original ask) and `scheduled_start_at`/`scheduled_end_at` (the final, Supervisor-editable schedule) to `service_requests`. Both pairs are nullable at the DB level — the 700 pre-existing rows have neither, and making them `NOT NULL` without a sensible default would have meant fabricating history data for real (well, demo) records that never had a schedule captured. "Required" is instead enforced at the application layer, and only for *new* records (`$request->exists ? 'nullable' : 'required'`), so the 700 legacy rows stay editable through their remaining workflow without suddenly failing validation.

`ServiceRequestWorkflow::assign()` now resolves the final schedule (an explicit override, falling back to `needed_start_at`/`needed_end_at`), requires a resolved schedule to exist at all, and logs to `activity_logs` only when the resolved schedule actually differs from the requester's original ask — so accepting the default produces no noise in the log.

### Calendar (Phases 4 and 5 built as one)

Rather than building two separate calendar implementations for "Supervisor/Super Admin" and "Service Staff," built one `ServiceCalendar` page whose event query is scoped through the exact same `ServiceRequest::visibleTo()` used everywhere else in the panel — a Service Staff member's calendar is already correctly limited to their own assignments by that scope, with zero new authorization logic. `canAccess()` deliberately does *not* reuse the shared `ViewAny:ServiceRequest` permission, because Requester also holds that (for their own request list) but should not get a calendar at all — a role check was used instead. The Services/Staff view toggle and its filters are hidden entirely for Service Staff (`canManageStaffView()`), who just see their own schedule by default. FullCalendar is registered once, panel-wide, via `Panel::assets()` (`Js::make()`, no `loadedOnRequest()`), traded a small amount of unnecessary load-on-every-page for avoiding `loadedOnRequest()`'s more fragile manual-include pattern in a small internal app. The container uses `wire:ignore` plus a `wire:key` keyed to the active filters, so Livewire never touches FullCalendar's own DOM and a filter change forces a clean destroy/remount instead of a partial diff.

### Conflict detection

The overlap engine (`existing_start < proposed_end AND existing_end > proposed_start`) lives in `ServiceRequestWorkflow`, split into a locked, transaction-safe `checkStaffAvailability()` (used inside `assign()`, re-checked at save time to close the race-condition window the spec asked about) and an unlocked `findScheduleConflict()` for read-only UI checks (disabling/labeling a staff option before the form is even submitted would be pointless to lock for). Only `Completed` is excluded as "terminal" — soft-deleted (cancelled) requests are excluded automatically by Eloquent's own default scope, and an elapsed window falls out of the overlap math for free, with no explicit "is this in the past" branch needed.

Building the 12-case boundary/edge-case test matrix surfaced a real, load-bearing constraint: `ServiceRequestPolicy` only allows cancellation while a request's status is `Submitted`, so "an already-assigned, scheduled request gets cancelled, freeing the staff member's slot" can never actually happen through the app today — assignment and cancellation are mutually exclusive states. The "cancelled releases the schedule" test soft-deletes directly at the DB level (bypassing the policy) specifically to prove the *query's* soft-delete exclusion is correct in isolation, while documenting that the workflow itself can't currently reach that state.

### Staff availability status

`StaffAvailabilityStatus` enum, 8 exact values, added as a new column on `users` (not a separate staff-profile table, since none exists) with a DB default of `available`. `isAssignable()` gates `assign()` outright for the six non-working statuses, independent of and prior to the schedule check — `Available`/`Busy` both still go through the normal overlap check, `Busy` never bypasses it. No automatic status transitions exist anywhere (confirmed and left that way per the spec's own instruction to report the limitation rather than build automation without a reliable trigger); a dedicated test proves completing an assignment does not silently flip a `Busy` staff member back to `Available`. Changes are gated behind a new `Update:StaffStatus` permission, granted to Supervisor/Super Admin only, and logged via `ActivityLog::record()`. The staff member's own status is shown read-only in the topbar identity partial — there is no reachable UI for them to change it themselves, which already satisfies "must not allow Service Staff to change their own status" without extra guard code.

### Default Requester role — a real conflict with an existing test, resolved deliberately

Wiring "every user gets Requester by default" into `UserFactory` itself (so *every* `User::factory()->create()` call anywhere would carry it) would have broken `BoundaryTest::test_create_and_cancel_are_requester_only`, which deliberately constructs a Service Staff/Supervisor user with an *exclusive* role specifically to prove they cannot create a service request — a genuine, existing security-boundary test, not incidental. Resolved by attaching `requester` at the real user-creation surfaces instead: `CreateUser`/`EditUser` pages' `afterCreate()`/`afterSave()` hooks, `UserImporter::afterSave()`, and both demo seeders — plus a new idempotent `app:ensure-default-requester-role` command for the 126 users that already existed, which only attaches the missing role and touches nothing else (no password, no other role, no name). Bare `User::factory()->create()->assignRole(...)` in tests stays exclusive-role by design; this is a deliberate scope boundary, not an oversight, and is called out here so it doesn't get "fixed" into a regression later.

Since every user can now hold `requester` alongside a functional role, a `roles->first()` lookup would non-deterministically show "Requester" instead of someone's actual role. Added `User::primaryRole()` (prefers the first non-`requester` role, falls back to `requester` only when that's genuinely all they have) and used it in both the topbar identity partial and the Users table's role column, which now shows only the functional role to avoid every single row displaying a redundant "Requester" badge.

While wiring this, found that `UserResource`'s role `Select` combined `->relationship('roles', 'name')` (which validates submitted values as the related role's *id*) with a custom `->options()` override that returned `name => name` pairs instead of `id => name` — so submitting the form always failed relationship validation with "the selected role is invalid." This is a pre-existing bug, never caught before because no test exercised `CreateUser`/`EditUser` through Livewire until this phase's tests did. Fixed by deleting the redundant override; `relationship()` already derives correct `id => name` options on its own.

### Verification

- Full PHPUnit suite: 88 tests, 469 assertions passed (all pre-existing tests still green, plus 7 new files).
- Pint passed for all affected PHP files.
- All 4 new migrations applied additively (`migrate --force`) against the real Postgres dev database — never `:fresh`, per this phase's explicit data-preservation rule. Verified the 700 existing requests and 126 existing users were untouched throughout.
- `HimoDemoDataSeeder` re-verified end-to-end against an isolated in-memory SQLite connection (the same isolation `FacilitiesTestCase` uses) after the schedule/conflict changes, specifically so the real dev database was never put at risk just to check the seeder still works — it does, with the new per-staff scheduling cursor added to keep the 700 generated requests from tripping the new conflict check against each other.
- `npm run build` passed.
- Column-by-column audit of every active seeder against the live schema (`php artisan db:table`) confirmed no stray or missing field references; the two disconnected legacy seeders (`DemoUserSeeder`, `ShieldRoleSeeder`, referencing roles that don't exist in this app) were flagged again as still present but inert, pending a decision on whether to delete them.

### Known limitation

The dev database's pre-existing 700 requests and 20 staff were never re-seeded, so they carry the new columns' defaults (`priority: normal`, `staff_status: available`, no schedule) rather than realistic variety — only records created after this phase show it. Fixing that requires either an authorized full reseed or a separate additive backfill script; neither was run without an explicit go-ahead.

## 2026-09-22 — Demo dataset, role-aware dashboards, and branding

### Bugs found while establishing a clean test baseline

Before writing the new seeder, ran the existing, untouched test suite to establish a baseline and found it already red on `main`. `ServiceRequestAssignment::create()` — called by `ServiceRequestWorkflow::assign()`, the "Supervisor assigns" step of the required workflow — threw `MassAssignmentException`, because the model declares `$guarded = ['*']` (blocks all mass assignment) while being written via `::create()`. Fixed by writing through `forceFill()->save()` instead, the same pattern the sibling `ServiceRequestStatusHistory` audit model already uses, rather than loosening the guard. Separately, `User::canAccessPanel()` was rejecting freshly created accounts: `status` has a DB-level default of `'active'`, but Eloquent doesn't hydrate DB-level defaults back into a just-created in-memory model, so `$user->status` stayed `null` until the record was reloaded — added `protected $attributes = ['status' => 'active']`, the same in-memory-default pattern `ServiceRequest` already uses for its own `status` column. A parallel bug (`SystemBackup`/`SystemRestore`, also fully guarded but created via `::create()`) made backup/restore entirely non-functional; fixed by relaxing the guard on those two internal, service-populated-only models instead, since an existing test exercises `::create()` on them directly and locks in that contract.

### Demo dataset

`HimoDemoDataSeeder` seeds 5 categories, 5 supervisors (one nominally responsible per category, for seeding realism only — the app has no supervisor/category ownership concept and none was added), 20 staff (4 per category), 100 requesters, 1 Super Admin, and 700 requests. Every request is produced by calling the real `ServiceRequestWorkflow` methods, never a raw insert, with `Carbon::setTestNow()` walking fake "now" forward through each request's lifecycle so `created_at`/`assigned_at`/`completed_at` stay chronologically consistent and the completion business rule is exercised for real on every record. Status distribution is fixed at 21/21/28/14/56 per category (15/15/20/10/40%), generation is deterministic (`mt_srand`/`fake()->seed()` with a fixed seed), and the seeder is idempotent — guarded by a fixed Super Admin email so a rerun against a non-empty database is a no-op.

`DatabaseSeeder` now calls this plus `FacilitiesRoleSeeder` instead of a stale `ShieldRoleSeeder`/`DemoUserSeeder` pair whose roles (`custodian`, `approver`) don't exist anywhere else in this app — apparent leftovers from a different case-study scaffold. Also removed `WithoutModelEvents` from `DatabaseSeeder`: it silently disabled the `creating`/`saving`/`saved` hooks that set `created_by`, validate the completion rule, and write status history, which would have broken every request this seeder creates.

### Role-aware dashboards

Six new widgets — `RequestStatusOverview` (full status breakdown plus average resolution time, computed in PHP rather than a driver-specific SQL date-diff function so it works identically on Postgres, MySQL, and the SQLite test connection), `CategoryVolumeChart`, `RequestTrendChart`, `StaffWorkloadWidget`, `RecentRequestsWidget`, `RecentlyCompletedWidget` — gated behind new `View:*` permissions granted to `service_supervisor` and inherited by `super_admin`. All of them reuse the existing `ServiceRequest::visibleTo()` scope rather than adding new authorization logic: the codebase already treats Service Supervisor as seeing every request, not one scoped to a category or office (confirmed by the existing, already-passing `AuthorizationTest`), and the case-study spec defines no per-category supervisor-ownership concept either — building a new supervisor↔category relationship to scope the dashboard further would have meant inventing a field the spec and code don't support. Removed `AccountWidget`/`FilamentInfoWidget` (the default "Welcome to <app>" card and Filament's own branding links) from the panel's `widgets()`.

### Branding, navigation, and identity

Logos moved from the repo root into `public/images/branding/` (byte-identical; dimensions verified unchanged: 1777×1644 and 1944×809). Panel colors wired through Filament's existing `->colors()` API: `primary` → UP Maroon `#7B1113`, `success` → Forest Green `#014421`, and a new `gold` (`#FFC72C`, approximating Pantone 1235C) used once, on the dashboard's "Unassigned" stat, since the brief asked for a sparing accent rather than a status color. `danger`/`warning`/`info` were left at Filament's defaults so error/warning states stay visually distinct from the maroon primary. Five navigation groups (Service Requests, Service Management, Users & Access, Reports & Monitoring, System Administration) added via `navigationGroups()`, with an icon and group assigned to every resource/page this repo controls; Filament Shield's own Roles page has no config hook for this without extending its vendor resource, so it keeps its default icon and sits outside the named groups. `sidebarCollapsibleOnDesktop()` enabled — Filament's standard icon-rail collapse, no custom JS. A `USER_MENU_BEFORE` render hook shows the authenticated user's name and a humanized role label (e.g. "Service Supervisor", never the raw `service_supervisor` slug) to the left of the profile avatar.

A UP+HIMO dual-logo lockup was added to the nav via `brandLogo()`, sized once against Filament's `--topbar-height: 4rem`, then resized larger on request. The user then reported it still crowds the nav after a hard refresh. Root-caused that OPcache (`revalidate_freq=0`) and Blade's view cache both pick up file changes on every request in this stack, so the report isn't a stale-cache issue — the layout itself needs rework or removal. Not yet resolved as of this entry; the code still has `brandLogo()`/`brandLogoHeight()` wired in `AdminPanelProvider`.

### Verification

- Full PHPUnit suite: 39 tests, 297 assertions passed (35 pre-existing, including the 2 fixed above, plus 4 new in `DashboardScopeTest`).
- Pint passed for all affected PHP files.
- `php artisan migrate:fresh --seed` against the real Postgres dev database (not just the SQLite test connection): completed in ~55s, produced exactly 126 users / 5 categories / 700 requests in the target distribution, zero business-rule or date-consistency violations; rerun is a no-op.
- `npm run build` passed.
- No connected browser in this session: the sidebar collapse animation and on-screen logo/identity layout were verified by reading the installed Filament CSS/Blade source rather than visually, which is how the still-open logo-sizing complaint above was missed initially.

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
