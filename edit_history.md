# Edit History

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
