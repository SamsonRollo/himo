# Changelog

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
