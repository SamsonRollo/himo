# Facilities role & workflow audit

Cross-reference of every role against every view/route/action in the Facilities module, checked against `09_facilities_service_requests_balanced.md` and against the actual permission seeders/policies as of this change. One bug was found (Service Categories leaking to Requester/Service Staff) and fixed; everything else already matched.

Legend: ✅ allowed, ❌ blocked. Rows are grouped by feature; "Enforced by" names the concrete guard.

## Service Categories

| Action | Requester | Service Staff | Service Supervisor | Super Admin | Enforced by |
|---|---|---|---|---|---|
| See nav item / list / view | ❌ | ❌ | ✅ | ✅ | `ServiceCategoryPolicy::viewAny/view` gated by `ViewAny:ServiceCategory`/`View:ServiceCategory`, now granted only to `service_supervisor`/`super_admin` in `FacilitiesRoleSeeder` (previously also granted to `requester`/`service_staff` — **the bug fixed by this change**) |
| Create / Edit | ❌ | ❌ | ✅ | ✅ | `ServiceCategoryPolicy::create/update` (permission + explicit role check) |
| Pick a category when submitting a request | ✅ (form only, no resource access) | n/a | n/a | n/a | `ServiceRequestResource` form queries `ServiceCategory` directly, bypassing the resource/policy |

## Service Requests

| Action | Requester | Service Staff | Service Supervisor | Super Admin | Enforced by |
|---|---|---|---|---|---|
| Submit new request | ✅ (own) | ❌ | ❌ | ✅ | `ServiceRequestPolicy::create` |
| Cancel request (Submitted, own) | ✅ | ❌ | ❌ | ✅ | `ServiceRequestPolicy::delete`, surfaced as `ServiceRequestResource::cancelAction()` |
| View own / assigned / all | own only | assigned + history | all | all | `ServiceRequest::scopeVisibleTo()` |
| Assign to staff | ❌ | ❌ | ✅ | ✅ | `ServiceRequestPolicy::assign` |
| Start work / update work note | ❌ | ✅ (own assignment only) | ❌ | ✅ | `ServiceRequestPolicy::canWork` |
| Submit for confirmation | ❌ | ✅ (own assignment only) | ❌ | ✅ | `ServiceRequestPolicy::submitForConfirmation` |
| Complete | ✅ (own, at For Confirmation) | ❌ | ✅ | ✅ | `ServiceRequestPolicy::complete` |
| Return for correction | ✅ (own, at For Confirmation) | ❌ | ✅ | ✅ | `ServiceRequestPolicy::returnForCorrection` |
| Active / History tabs | own split by completion | current + past assignments split by completion | own split | own split | `ListServiceRequests::getTabs()`, `service_request_assignments` log |

No "Reopen from Completed" transition exists — Completed remains terminal, per the already-judged core business rule. The equivalent alternate paths are Cancel (Submitted only) and Return for correction (For Confirmation → In Progress).

## User Management (new)

| Action | Requester | Service Staff | Service Supervisor | Super Admin | Enforced by |
|---|---|---|---|---|---|
| List / view / create / edit users | ❌ | ❌ | ❌ | ✅ | `UserPolicy`, gated by `*:User` permissions granted only to `super_admin` |
| Deactivate / reactivate | ❌ | ❌ | ❌ | ✅, except self or the last active admin | `UserPolicy::delete` |
| CSV import / export | ❌ | ❌ | ❌ | ✅ | `UserResource` header actions, same policy |
| Import can create/promote a `super_admin` | — | — | — | ❌ (always blocked) | `UserImporter::resolveRecord()` rejects rows targeting a super_admin email; role column is restricted to the three domain roles |

Deactivation never deletes the row (`deleted_at` is reserved as a defense-in-depth block on hard deletes, never used in normal operation) — `created_by`/`assigned_to`/history relations on existing requests are untouched, and `canAccessPanel()` locks a deactivated account out immediately.

## Backup & Restore (new)

| Action | Requester | Service Staff | Service Supervisor | Super Admin | Enforced by |
|---|---|---|---|---|---|
| View / create / download / restore / delete backups | ❌ | ❌ | ❌ | ✅ | `SystemBackups::canAccess()` gated by `Manage:SystemBackup`, granted only to `super_admin` |

Backups are stored under `storage/app/private/backups` (the `local` disk, never web-served). Restore requires typing the exact backup filename to confirm, checksums the file before restoring, wraps the operation in `pg_restore --single-transaction` plus an application-wide maintenance window, and always writes a `SystemRestore` audit row — including on failure.

## Dashboard, report, and history

Unchanged by this audit — `ServiceRequestMetrics` and `ServiceAccomplishmentReport` already scope by `ServiceRequest::visibleTo()`/the same permissions, and `StatusHistoriesRelationManager` already re-checks `view` on its owner record directly (not just tab visibility). No gaps found here.

## Edge cases verified

- A deactivated Service Staff member cannot be newly assigned (`User::scopeActive()` filters both the assign dropdown and `ServiceRequestWorkflow::assign()`'s server-side validation), but their existing assignments and status history remain fully visible and attributed.
- CSV import: unmapped/invalid role values are rejected per-row with a reported reason; duplicate emails update the existing account only when "update existing" is checked, otherwise the row is reported as skipped with a reason; a row can never target the `super_admin` role or an existing `super_admin` account.
- The last active `super_admin` cannot deactivate themselves or reduce the count to zero (`UserPolicy::delete`).
- Restoring from a backup whose file is missing or whose checksum no longer matches is refused before any destructive action runs, and the refusal is still recorded as a failed `SystemRestore` row.
