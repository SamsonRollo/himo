# Changelog

## Unreleased — Priority, scheduling, staff calendar/availability, and default Requester role

### Added

- Service request `priority` (`low`/`normal`/`high`/`urgent`/`critical`, default `normal`), shown as icon+text badges (never color alone) in forms, tables, filters, and the calendar.
- Requester-supplied `needed_start_at`/`needed_end_at` schedule (required on new requests, end must be after start) and a Supervisor-editable `scheduled_start_at`/`scheduled_end_at` final schedule, defaulting to the requester's window and overridable at assignment.
- `activity_logs`: a new append-only, polymorphic audit table (mirrors `service_request_status_histories`'s shape) used for schedule overrides and staff-availability changes — the only two changes in this phase with no existing dedicated history table.
- `ServiceCalendar` page (day/week/month via FullCalendar.js, loaded from a CDN — no Filament-v5-compatible calendar package exists yet): full visibility + Services/Staff toggle + staff/category/priority/status filters for Supervisor/Super Admin; own-schedule-only for Service Staff, via the existing `visibleTo()` scope.
- Staff assignment conflict detection (`existing_start < proposed_end AND existing_end > proposed_start`), enforced inside `assign()`'s row-locked transaction; the assign form visibly labels and disables unavailable staff instead of just hiding them.
- Service Staff availability status (`available`/`busy`/`on_leave`/`official_business`/`in_training`/`out_of_office`/`absent`/`inactive`, default `available`), gating assignability independently of the schedule check, editable only by Supervisor/Super Admin, visible read-only to the staff member.
- `requester` is now the default role for every user, on every creation path (admin form, CSV import, seeders) plus an idempotent `app:ensure-default-requester-role` backfill command for existing accounts.
- 7 new test files covering all of the above, including a 12-case boundary/edge-case matrix for assignment conflicts.

### Changed

- Service Supervisor gained read-only access to the user listing plus a narrow "set staff availability" action — explicitly not `Create`/`Update`/`Delete:User`.

### Fixed

- `UserResource`'s role field combined `relationship()` (expects role IDs) with a custom `options()` override (returned role names) — every user create/edit through the admin UI was silently broken. Never caught before because no test exercised that form; now fixed and covered.

### Verification

- Full PHPUnit suite: 88 tests, 469 assertions passed.
- Pint passed for all affected PHP files.
- All 4 new migrations applied additively (`migrate --force`, never `:fresh`) against the real dev database — the existing 700 requests and 126 users were preserved throughout.
- Vite production build passed.
- Confirmed column-by-column against the live schema that every active seeder (`FacilitiesRoleSeeder`, `HimoDemoDataSeeder`, `FacilitiesDemoSeeder`) writes only real columns.

### Known limitation

- The dev database's existing 700 requests / 20 staff were never re-seeded (data-preservation rule), so they don't show priority/schedule/availability variety — only newly created records do. Two unused, disconnected seeders (`DemoUserSeeder`, `ShieldRoleSeeder`) still reference roles (`custodian`, `approver`) that don't exist in this app; left in place pending a decision to delete them.

## Unreleased — Demo dataset, role-aware dashboards, and branding

### Added

- `HimoDemoDataSeeder`: 5 service categories, 5 supervisors, 20 staff, 100 requesters, 1 Super Admin, and 700 service requests in a fixed status distribution, produced entirely through `ServiceRequestWorkflow` (never a raw insert) so every seeded record obeys the completion business rule and writes real status history/assignment log entries. Deterministic (fixed Faker/`mt_srand` seed) and idempotent.
- Six role-gated dashboard widgets for Service Supervisor/Super Admin: `RequestStatusOverview` (status breakdown + average resolution time), `CategoryVolumeChart`, `RequestTrendChart`, `StaffWorkloadWidget`, `RecentRequestsWidget`, `RecentlyCompletedWidget`.
- Institutional branding: UP/HIMO logos in the panel nav, UP Maroon / Forest Green / Gold theme colors via Filament's `->colors()`, and a name + humanized-role identity block in the topbar user menu.
- Five ordered navigation groups (Service Requests, Service Management, Users & Access, Reports & Monitoring, System Administration) with an icon on every resource/page.
- Desktop-collapsible sidebar (`sidebarCollapsibleOnDesktop()`).
- `tests/Feature/Facilities/DashboardScopeTest.php`: widget authorization matrix and aggregate-query correctness (counts, average resolution time, staff workload, scoped recency lists).

### Changed

- `DatabaseSeeder` now seeds `FacilitiesRoleSeeder` + `HimoDemoDataSeeder` instead of a stale `ShieldRoleSeeder`/`DemoUserSeeder` pair referencing roles (`custodian`, `approver`) unused anywhere else in the app. Also dropped `WithoutModelEvents`, which was silently suppressing `created_by` tracking, status-history writes, and the completion business rule.
- Removed the default `AccountWidget`/`FilamentInfoWidget` ("Welcome to ..." card and Filament's own branding links) from the dashboard.

### Fixed

- `ServiceRequestWorkflow::assign()` always threw `MassAssignmentException` (the "Assign" step of the required workflow never actually worked): `ServiceRequestAssignment` is fully guarded but was written via `::create()`. Switched to `forceFill()->save()`, matching the sibling `ServiceRequestStatusHistory` pattern.
- `SystemBackup`/`SystemRestore` had the same fully-guarded/`::create()` mismatch, breaking backup and restore outright; relaxed guarding on both.
- A freshly created `User` had `status = null` in memory (no in-memory default, only a DB-level one), so `canAccessPanel()` rejected brand-new accounts until reloaded from the database. Added an in-memory default, matching the pattern `ServiceRequest` already uses.

### Verification

- Full PHPUnit suite: 39 tests, 297 assertions passed (35 pre-existing + 4 new), including 2 pre-existing tests that only started passing once the fixes above landed.
- Pint passed for all affected PHP files.
- `php artisan migrate:fresh --seed` against the real Postgres dev database: 126 users, 5 categories, 700 requests in the target distribution, zero business-rule or date-consistency violations.
- Vite production build passed.

### Known issue

- The nav logo lockup (UP + HIMO side by side) is still reported as crowding the nav even after one round of resizing; not yet resolved.

## Unreleased — Facilities RBAC audit, user management, and backup/restore

### Added

- Service Category visibility and management strictly limited to Service Supervisor and Super Admin (previously leaked read access to Requester and Service Staff).
- Requester-facing "Cancel request" action (relabeled from the generic delete, same authorization).
- Append-only Service Request assignment log, independent of the mutable `assigned_to` column, plus Active/History tabs on the Service Requests list.
- Super Admin user management: create/edit, non-destructive deactivate/reactivate (hard deletes are blocked outright), and CSV import/export via Filament's built-in importer/exporter.
- Server-side database backup and restore (Super Admin only), backed by `pg_dump`/`pg_restore`, stored privately on the server, with checksum verification, a typed-filename confirmation, a maintenance-mode window during restore, and a full audit trail for both actions.
- `docs/facilities/rbac-audit.md`: full role × action cross-reference against the case study spec.

### Changed

- `users` table gains `status`/`deactivated_at`/`deactivated_by` and soft deletes; `User::canAccessPanel()` now also requires an active status.
- Staff-assignment dropdowns and `ServiceRequestWorkflow::assign()` now only offer/accept active Service Staff.

## Unreleased — Facilities and Service Request Management MVP

### Added

- Service Category management with active/inactive state, authenticated ownership, and soft deletion.
- Service Requests with generated request numbers, location, category, description, assignment, status, completion note, and completion time.
- Required workflow: Submitted → Assigned → In Progress → For Confirmation → Completed.
- Return-for-correction path from For Confirmation to In Progress, requiring a reason and retaining the staff assignment.
- Server-side completion rule: a request requires assigned staff and a non-empty completion note before it can be completed.
- Append-only status history with actor, timestamp, source status, target status, and remarks.
- Service Staff and Service Supervisor roles, domain permissions, record policies, and role-scoped visibility.
- Filament resources for categories and service requests, including search, filters, status badges, workflow actions, and visible history.
- Dashboard metrics for open, assigned, in-progress, and completed requests.
- Service accomplishment report filterable by category and status.
- Idempotent non-production demonstration data with three dedicated facilities accounts, two categories, and six sample requests.
- Required system context, ERD, process flow, and Level 0 DFD diagrams.

### Changed

- Shield role management now exposes facilities permissions in its existing custom-permissions tab and preserves the custom domain policies during Shield generation.

### Verification

- Full PHPUnit suite: 24 tests, 185 assertions passed.
- Pint passed for all 35 affected PHP files.
- Vite production build passed.
- PostgreSQL rejected invalid Completed updates missing staff, a note, or a non-whitespace note.

See [the detailed edit history](edit_history.md), [demo guide](docs/facilities/demo.md), and [implementation diagrams](docs/facilities/diagrams.md).
