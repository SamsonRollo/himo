# Codex QA Assessment — Facilities and Service Request Management

**Assessment date:** 23 September 2026  
**Baseline:** `docs/ai/09_facilities_service_requests_balanced.md`  
**Assessment scope:** Current repository source, migrations, tests, Filament implementation, seeders, and Facilities documentation. No application source, configuration, migration, seeder, or test files were modified for this assessment.

## Verdict

**Core MVP: passes functional QA. Submission deliverables: conditional pass.**

The required facilities workflow, role-based visibility, completion rule, ownership tracking, soft deletion, dashboard metrics, report, history, demonstration data, and alternate path are implemented and supported by passing facilities-specific automated tests. Two required-documentation gaps prevent a fully compliant submission: the PDCA reflection is absent, and the ERD does not match the final migrations. The full test suite also has one failing branding assertion that must be reconciled with the current login-logo implementation.

## Verification evidence

| Check | Result | Evidence |
|---|---|---|
| Facilities test suite | Pass | `docker compose exec -T php php artisan test --compact tests/Feature/Facilities`: **90 passed, 511 assertions**. |
| Full application test suite | Fail | `docker compose exec -T php php artisan test --compact`: **94 passed, 1 failed, 534 assertions**. `Tests\Feature\BrandingTest::test_login_page_places_the_two_logo_images_before_the_sign_in_heading` still expects the former fixed `height: 2rem` and `height: 1.25rem` declarations. |
| Migration status | Pass | All 18 listed migrations, including facilities, assignment-history, priority, scheduling, activity-log, and staff-status migrations, show `Ran`. |
| Admin routes | Pass | `php artisan route:list --path=admin` lists the service request, category, report, calendar, user, backup, login, and dashboard routes. |
| Interactive browser/diagram rendering | Not performed | No browser or Mermaid renderer was available to independently validate visual layout or rendered diagrams. |

## Traceability matrix

| Specification requirement | Status | Evidence and assessment |
|---|---|---|
| Requester, Service Staff, Service Supervisor roles | Pass | `FacilitiesRoleSeeder` grants domain permissions to all three roles. `ServiceRequestPolicy` differentiates requester ownership, staff assignment, and supervisor actions. Super Admin is an additional administrative role. |
| Service Category master-data management | Pass | `ServiceCategory` and its migration/resource provide create, update, soft delete, active state, search, filtering, ownership, and policy enforcement. Category access is denied to requester and staff in `AuthorizationTest`. |
| Service Request transaction management | Pass | `ServiceRequest`, its resource, factory, and workflow service capture category, location, description, current status, assignee, completion note, dates, ownership, and generated request number. |
| Required statuses: Submitted, Assigned, In Progress, For Confirmation, Completed | Pass | `ServiceRequestStatus` and the `service_requests.status` database enum contain all five required states. |
| Requester submits → Supervisor assigns → Staff works → For Confirmation → Requester/Supervisor completes | Pass | `ServiceRequestWorkflow` exposes submit, assign, start, updateWork, submitForConfirmation, and complete operations. `WorkflowTest` and `InterfaceTest` cover the workflow. |
| Completion needs assigned staff and non-empty completion note | Pass, defence in depth | `ServiceRequest` rejects invalid saves; `ServiceRequestWorkflow` restricts changes to authorized transitions; `ServiceRequestPolicy::complete()` requires For Confirmation; PostgreSQL migration adds `service_requests_completion_check`. Direct-completion cases pass in `DataFoundationTest`, `WorkflowTest`, and `BoundaryTest`. |
| Alternate path | Pass | `returnForCorrection()` returns a request to In Progress, keeps the assignment, clears the prior note, and records the reason. An owner may also soft-delete an untouched submitted request through the cancellation action. |
| Server-side authorization and record visibility | Pass | `ServiceRequest::scopeVisibleTo()` and policies scope requester records to ownership, staff records to current or historical assignments, and supervisor/Super Admin records to the operational set. Authorization tests include direct URL/resource checks. |
| Automatic `created_by` / `updated_by`; creator not selectable | Pass | `TracksAuthenticatedOwnership` assigns authenticated ownership and prevents creator reassignment. The request and category forms do not expose `created_by`. |
| Soft deletion for primary records | Pass | `ServiceCategory` and `ServiceRequest` use `SoftDeletes`; migrations provide `deleted_at`; normal cancellation/delete uses model deletion. Tests verify normal queries exclude deleted records. |
| Search, filters, and visible current status | Pass | `ServiceRequestResource` has searchable request number/location/description and status/category/priority filters. Status is rendered as a labeled badge. Categories have search and active-state filter. |
| Dashboard metrics: open, assigned, in-progress, completed | Pass | `ServiceRequestMetrics::counts()` supplies all four required measures from the user-visible query. `DashboardScopeTest` verifies calculations and role scoping. |
| Accomplishment report by category and status | Pass | `ServiceAccomplishmentReport` is a scoped table with category and status filters and an export action. `ReportingAndDemoTest` verifies filtering, visibility, and CSV output. |
| Basic audit/history view | Pass | Immutable `ServiceRequestStatusHistory` rows are written for status changes and displayed by the read-only `StatusHistoriesRelationManager`. |
| Seeded demonstration data | Pass | `DatabaseSeeder` calls `FacilitiesRoleSeeder` and `HimoDemoDataSeeder`; `FacilitiesDemoSeeder` supplies a focused six-request scenario. `ReportingAndDemoTest` verifies idempotency and expected workflow/report/history data. |
| End-to-end demo scenario | Pass | `docs/facilities/demo.md` provides a role-by-role demonstration including successful completion, return for correction, and prohibited direct completion. The workflow is also covered by tests. |
| System Context Diagram | Pass, content present | Present in `docs/facilities/diagrams.md`. It identifies the three core roles, application boundary, and extra Super Admin. |
| ERD | **Fail** | Present, but it does not agree with the final migrations; see Finding F-02. |
| Process Flow Diagram | Pass, core flow | Present with the required review decision, completion-rule decision, and return-for-correction path. It depicts the required core workflow. |
| Level 0 DFD | Pass, core flow | Present with actors, five processes, and category/request/history/identity stores. |
| PDCA explanation/reflection | **Fail** | Required by the specification but absent. `docs/facilities/demo.md` and `docs/facilities/verification.md` explicitly say PDCA was excluded pending separate approval. |

## Findings

### F-01 — Required PDCA reflection is missing

**Severity:** High for submission completeness  
**Status:** Open

The baseline requires a Plan–Do–Check–Act explanation and a short presentation reflection. No PDCA document or section was found. The repository instead records that PDCA was deliberately excluded in `docs/facilities/demo.md` and `docs/facilities/verification.md`.

The core test suite already contains evidence suitable for the required Check scenarios: a normal completion flow, return-for-correction flow, and invalid completion attempts. That evidence does not replace the required written/presented PDCA explanation.

### F-02 — ERD and diagram narrative do not match the final migrations

**Severity:** High for required-diagram compliance  
**Status:** Open

The ERD in `docs/facilities/diagrams.md` says that only the three migrations `2026_09_22_070000` through `2026_09_22_070002` are new and that there is no Assignment table. This contradicts the current migration set and implementation:

- `2026_09_22_080000_create_service_request_assignments_table.php` creates `service_request_assignments`, which is actively written by `ServiceRequestWorkflow::assign()`.
- `2026_09_22_115609_add_priority_to_service_requests_table.php` adds `priority`.
- `2026_09_22_120201_add_schedule_to_service_requests_table.php` adds requested and scheduled date columns.
- `2026_09_22_122210_add_staff_status_to_users_table.php` adds `staff_status`.
- Other post-core migrations add user status/soft deletion, activity logs, backups, and restores.

The case study explicitly requires the ERD to agree with the final Laravel migrations. The diagram is useful for the original core scope, but is not an accurate representation of the shipped schema.

### F-03 — Full automated suite is not green

**Severity:** Medium  
**Status:** Open

The full test suite fails only at `Tests\Feature\BrandingTest::test_login_page_places_the_two_logo_images_before_the_sign_in_heading`. The test asserts the old literal login logo heights (`2rem` and `1.25rem`), while the current login page uses responsive custom-property sizing. The facilities suite remains green.

This is not a facilities workflow failure, but it prevents reporting a fully passing repository test suite. The assertion and current UI requirement need to be synchronized before release verification.

### F-04 — Scope materially exceeds the requested MVP

**Severity:** Informational  
**Status:** Accepted only if core scope remains the presentation focus

The implementation includes scheduling/conflict detection, staff availability, calendar views, user import/export, activity logs, backups/restores, and richer analytics. These are not necessary to satisfy the required core MVP. The specification says optional expansion should not displace the required workflow or earn credit while required work remains incomplete.

The additional features do not negate compliance, but the demonstration should lead with the required workflow and mandatory diagrams/PDCA evidence.

## Deliberate schema choices that are acceptable

The baseline labels the entity/column guide as suggested, not mandatory. The following current choices are reasonable and do not count as failures:

- `created_by` is the requester/owner; no duplicate `requested_by` column exists.
- `location` is a validated string rather than a separate Location master table.
- Assignment is modeled both as the current `assigned_to` pointer and an append-only `service_request_assignments` history table.
- Feedback is not implemented; it is a suggested entity outside the required core workflow.

## QA conclusion

The facilities core is functionally ready: its 90 focused tests pass, required workflow transitions are explicit and authorized, invalid completion is rejected at multiple layers, and the required operating views exist.

For a fully compliant submission, resolve F-01 and F-02, then make the full suite green by resolving F-03. No assessment finding requires changing the core transaction workflow or the installed technology stack.

