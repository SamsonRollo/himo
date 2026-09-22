# QA Assessment (Claude) — Facilities and Service Request Management

**Assessment date:** 2026-09-22
**Baseline:** `09_facilities_service_requests_balanced.md`
**Method:** Static review of migrations, models, policies, Filament resources/pages/widgets, seeders, tests, and docs, plus independent execution of the automated test suite and Pint against the live Docker stack (PHP 8.5, PostgreSQL 16 container; host PHP 8.4.23 also available). No files were modified as part of this assessment.

**Verdict: Functionally compliant with the required MVP. One mandatory deliverable is missing (PDCA reflection), and the ERD/diagram documentation is stale relative to the schema actually shipped.**

---

## 1. Independent verification performed

| Check | Result |
|---|---|
| `docker compose exec -T php php artisan test --compact` | **92 passed, 513 assertions**, 25.08s, 0 failures |
| `docker compose exec -T php php vendor/bin/pint --test` | 113/115 files clean; 2 pre-existing files fail style (`app/Policies/RolePolicy.php`, `bootstrap/providers.php` — both from the initial scaffold commit, not touched by the Facilities feature) |
| `docker compose exec -T php php artisan migrate:status` | All 18 migrations applied, none pending |
| `docker compose ps` | php, nginx, pgdb, redis, vite, adminer all up/healthy |
| `git log` scope review | Confirms the Facilities work landed across 6 commits, most recently `d716155` ("Add priority, scheduling, calendar, conflict detection, staff availability, and default Requester role") |

These figures match the repo's own `docs/facilities/verification.md`/`Assessment.md` for the test/Pint counts, so I treat the prior in-repo self-assessments as reliable on those specific points; the rest of this document is my own independent read of the code.

---

## 2. Requirement-by-requirement traceability

| # | Requirement (from the case study) | Status | Evidence |
|---|---|---|---|
| 1 | 3 primary roles: Requester, Service Staff, Service Supervisor | ✅ Met | `database/seeders/FacilitiesRoleSeeder.php`, role checks throughout `ServiceRequestPolicy`/`ServiceCategoryPolicy`. An additional Super Admin role exists for platform administration, which is normal and does not violate the "3 primary roles" requirement. |
| 2 | 1 master-data module: Service Category | ✅ Met | `ServiceCategory` model/migration/resource: `name`, `description`, `is_active`, ownership columns, `SoftDeletes`. Matches the suggested schema field-for-field (`database/migrations/2026_09_22_070000_create_service_categories_table.php`). |
| 3 | 1 main transaction module: Service Request | ✅ Met | `ServiceRequest` model/migration/resource. All suggested columns present except `requested_by` (see §3 below — deliberate, documented design choice, not an omission). |
| 4 | 4–5 required statuses | ✅ Met | `ServiceRequestStatus` enum + DB `enum` column: `submitted, assigned, in_progress, for_confirmation, completed` — exact match to spec (`app/Enums`, `database/migrations/2026_09_22_070001_...`). |
| 5 | Required workflow: Requester submits → Supervisor assigns → Staff works/updates → For Confirmation → Requester/Supervisor completes | ✅ Met | `app/Services/ServiceRequestWorkflow.php` implements `submit → assign → start → updateWork → submitForConfirmation → complete`, each gated by `ServiceRequestPolicy` and status guards. Verified against `WorkflowTest.php`. |
| 6 | Required business rule: cannot mark Completed without assigned staff + completion note | ✅ Met, defense in depth | Enforced in **four independent layers**: (a) `ServiceRequest::booted()` `saving` validation (`app/Models/ServiceRequest.php:78-80`), (b) `ServiceRequestWorkflow::change()`/`complete()` transactional workflow path, (c) `ServiceRequestPolicy` gates on `complete`, and (d) a PostgreSQL `CHECK` constraint (`service_requests_completion_check`) rejecting the same condition at the database layer with a non-whitespace check on the note. This exceeds the spec's minimum. |
| 7 | Suggested entities: ServiceRequest, ServiceCategory, Location, Assignment, StatusHistory, Feedback | ⚠️ Partially met (by design) | `ServiceRequest`, `ServiceCategory`, `StatusHistory` (`ServiceRequestStatusHistory`) are implemented as specified. `Location` is a plain string column rather than a table (spec allows this — "suggested," not mandatory). `Assignment` **is** implemented, as an append-only `ServiceRequestAssignment` log — but see §5, the in-repo diagram documentation incorrectly claims no such table exists. `Feedback` is not implemented at all; it is optional per spec and not part of the 13-item required demonstration list, so this is not a compliance gap. |
| 8 | Minimum dashboard: Open / Assigned / In-progress / Completed | ✅ Met, and exceeded | `RequestStatusOverview` widget shows all four required buckets plus a "For confirmation" split and average resolution time; additional widgets (`CategoryVolumeChart`, `RequestTrendChart`, `StaffWorkloadWidget`, `RecentRequestsWidget`, `RecentlyCompletedWidget`) go beyond the minimum. Scoped per-user via `ServiceRequest::visibleTo()`. |
| 9 | Minimum report: Service accomplishment report by category and status | ⚠️ Met, minimally | `ServiceAccomplishmentReport` Filament page is a filterable/sortable table with category and status columns and filters (`app/Filament/Pages/ServiceAccomplishmentReport.php`). It satisfies "a report/output" literally, but it is a raw filtered list, not an aggregated category × status summary, and there is no export/PDF/print action anywhere in the codebase (`app/Filament/Exports` only has `UserExporter`, unrelated to Service Requests). A browser print of the Filament table is the only "printable" option. This is the weakest of the required deliverables, though it does not fail the letter of the requirement. |
| 10 | Basic audit/history view | ✅ Met | `StatusHistoriesRelationManager` shows append-only `ServiceRequestStatusHistory` records (from/to status, actor, remarks, timestamp), written transactionally with every save (`ServiceRequest::booted()::saved`). Read-only; separately policy-checked. |
| 11 | 4 required diagrams | ⚠️ Present but stale | `docs/facilities/diagrams.md` has all four diagram types (System Context, ERD, Process Flow, Level-0 DFD) in Mermaid. **However, this file was last committed before the `priority`, `scheduled_*`/`needed_*`, `service_request_assignments`, and staff-availability work landed** (see §5) — the ERD explicitly and now-incorrectly states "there is no redundant `requested_by`, Location, Assignment, or Feedback table," while an `Assignment`-equivalent table (`service_request_assignments`) does exist. The spec requires "The ERD should agree with the submitted Laravel migrations" — it currently does not. |
| 12 | PDCA reflection | ❌ Not met | No PDCA (Plan–Do–Check–Act) document exists anywhere in the repo. `docs/facilities/demo.md` and `edit_history.md` each explicitly state PDCA is excluded pending approval. This is listed as a required, judged deliverable ("Every team must... 1 short PDCA reflection"), not optional. |
| 13 | Seeded demonstration data | ✅ Met | `HimoDemoDataSeeder` (385 lines) generates ~5 categories, 126 users, and ~700 service requests spanning all five statuses; `FacilitiesDemoSeeder` provides a small, deterministic 6-request scenario for live demos. `DatabaseSeeder` wires both in correctly. |
| 14 | End-to-end demo scenario | ✅ Met | `docs/facilities/demo.md` walks through submit → assign → start → update note → confirm → complete, plus the required alternate path (return for correction) and the required negative case (direct-to-Completed rejection), each backed by a named automated test. |
| 15 | Standard MVP requirements: auto `created_by`, SoftDeletes, scoped visibility, `created_by` not user-selectable | ✅ Met | `TracksAuthenticatedOwnership` trait (`app/Models/Concerns/TracksAuthenticatedOwnership.php`) sets `created_by`/`updated_by` from `auth()->id()`, blocks changing `created_by` after creation, and even blocks force-deletion outright. `created_by` never appears as a form field in `ServiceRequestResource`/`ServiceCategoryResource`. Visibility is scoped via `ServiceRequest::scopeVisibleTo()`, applied in every resource/page/widget query. |
| 16 | Demonstrate: login, role-based access, master data CRUD, transaction creation, review step, alternate path, business rule, status visibility, search/filter, dashboard, audit trail, report, seeded data (the 13-point list) | ✅ All 13 present | Cross-checked individually against the code above; each item has a concrete implementation and, in most cases, a named feature test. |

---

## 3. Notable, deliberate deviations from the *suggested* schema (not violations)

The spec repeatedly says its data model is "suggested," not mandatory. The team documented its deviations in `docs/facilities/diagrams.md` and they are reasonable:

- **No `requested_by` column** — the authenticated creator (`created_by`) doubles as the requester, avoiding a duplicate owner column. Consistent with "avoid unnecessary fields... during the 1.5-day MVP."
- **`Location` is a string, not a table** — acceptable per spec wording.
- **`Assignment` is implemented**, but as an *additional* append-only `service_request_assignments` audit log rather than the sole source of truth for current assignment (`service_requests.assigned_to` remains the live pointer). This is a defensible design (keeps staff task history intact across reassignment) but, again, the diagrams doc claims it doesn't exist — see §5.

---

## 4. Scope well beyond the 1.5-day MVP target

The spec is explicit and repeated on this point: *"Teams should not add large optional modules until the core MVP is working,"* and *"The team should not receive extra credit simply for implementing a larger scope if the required MVP is incomplete."* The current implementation, on top of a fully working core MVP, also ships:

- A full scheduling subsystem: `needed_start_at/end_at`, `scheduled_start_at/end_at`, a calendar page (`app/Filament/Pages/ServiceCalendar.php`), and transactional staff-availability/overlap conflict detection (`ServiceRequestWorkflow::checkStaffAvailability()`/`findScheduleConflict()`).
- A staff availability status system (`StaffAvailabilityStatus` enum: Available, Busy, On Leave, Official Business, In Training, Out of Office, Absent, Inactive).
- Full User CRUD with CSV import/export (`UserImporter`, `UserExporter`, `imports`/`exports`/`failed_import_rows` tables) and deactivation/reactivation flows.
- A database backup/restore subsystem (`SystemBackup`, `SystemRestore` models, `BackupService`, `SystemBackups` Filament page) with checksums and maintenance-mode-gated `pg_restore`.
- A general-purpose `ActivityLog` model independent of the required `StatusHistory`.

None of these are functionally broken — they are well-tested (`AssignmentConflictTest`, `StaffAvailabilityTest`, `ServiceCalendarTest`, `BackupRestoreTest`, `UserManagementTest`, etc., 92 passing tests total) — but they represent substantial scope creep relative to a "target difficulty 3/5" hackathon MVP that is meant to match eight other teams' workload. For judging fairness this is a real risk: none of these features are on the spec's own "Optional/Bonus" list (SSO, email, QR, REST API, AI, advanced analytics, PDF workflows, digital signatures, external integration, complex multi-level approval, mobile app) because the spec's authors evidently didn't anticipate a team building them, but the spirit of the scope-control section clearly applies. **Recommendation: during the live demo, present only the required workflow first and treat everything above as bonus context, not the headline.**

---

## 5. Documentation is out of date relative to the shipped schema

This is the most concrete, checkable finding in this assessment:

- `docs/facilities/diagrams.md` was committed at `2026-09-22 14:50:35` (commit history via `git log`).
- The scheduling/priority/availability commit (`d7161557`, "Add priority, scheduling, calendar, conflict detection, staff availability, and default Requester role") landed at `2026-09-22 20:40:29`, **almost 6 hours later**, and added: `priority`, `needed_start_at`, `needed_end_at`, `scheduled_start_at`, `scheduled_end_at` to `service_requests`; the `activity_logs` table; and `staff_status` to `users`. It was preceded by an earlier commit that added `service_request_assignments`, `system_backups`, `system_restores`, and user status/soft-deletes — none of which are reflected in the ERD either.
- The diagrams file's ERD text explicitly and now-incorrectly states: *"there is no redundant `requested_by`, Location, Assignment, or Feedback table"* — but `service_request_assignments` (a real table backing the `ServiceRequestAssignment` model) exists and is actively used by the assignment workflow.
- The Process Flow diagram has no decision point for the staff-availability/overlap check that `ServiceRequestWorkflow::assign()` actually performs, and the DFD does not mention the assignment log, priority, or scheduling data at all.

The spec is explicit: *"All diagrams must describe the MVP actually implemented, not an ideal future enterprise system"* and *"The ERD should agree with the submitted Laravel migrations."* As committed, it does not. This is a mechanical, low-effort fix (regenerate the ERD/DFD from the current migrations) but it is currently a genuine nonconformance, not just a nice-to-have.

---

## 6. Other findings

| Severity | Finding | Detail |
|---|---|---|
| **High** | PDCA reflection is completely absent | See §2 row 12. This is listed as a required, judged deliverable, on par with the four diagrams. |
| **Medium** | ERD/diagrams stale vs. shipped schema | See §5. |
| **Medium** | Stock Laravel `README.md` | No project-specific setup, demo credentials, or test instructions; a reader unfamiliar with the repo would need to find `docs/facilities/demo.md` on their own to run anything. |
| **Medium** | Dead legacy seeders (`ShieldRoleSeeder`, `DemoUserSeeder`) reference `custodian`/`approver`/`panel_user` roles from an unrelated case study | Not called by `DatabaseSeeder`, so no functional impact, but they are a trap for anyone who runs `db:seed --class=ShieldRoleSeeder` expecting Facilities roles. |
| **Low** | "Accomplishment report" is a filtered list, not an aggregated category × status summary, and has no export/print action | Technically satisfies "1 report/output" but is the thinnest interpretation of it. |
| **Low** | Two pre-existing files fail Pint (`app/Policies/RolePolicy.php`, `bootstrap/providers.php`) | From the initial scaffold commit, unrelated to the Facilities feature; harmless but present in `--test` output. |
| **Informational** | `docs/facilities/demo.md` §"RBAC, user management, and backup/restore checklist" self-reports it was "written without access to a live database or Docker in the implementing sandbox... and must be exercised manually here before being trusted" | I did not independently re-verify this checklist item by item (it covers the bonus User Management/Backup features, not the required MVP). Flagging so it isn't mistaken for something this assessment confirmed. |

---

## 7. Strengths worth highlighting

- **Defense-in-depth on the one graded business rule.** Completion is blocked by model validation, workflow-service logic, policy authorization, and a PostgreSQL `CHECK` constraint simultaneously — this is more rigorous than the MVP requires and is the single most judge-relevant piece of the submission.
- **Server-side authorization, not just hidden UI.** Every workflow action (`assign`, `start`, `updateWork`, `submitForConfirmation`, `complete`, `returnForCorrection`) is gated by an explicit `ServiceRequestPolicy` method and re-checked inside a row-locked DB transaction (`ServiceRequestWorkflow::change()`), closing races between concurrent actions.
- **Consistent ownership pattern.** `TracksAuthenticatedOwnership` implements the spec's required `created_by`/`updated_by`/soft-delete pattern once and reuses it, rather than duplicating the logic per model.
- **Real automated coverage of the three PDCA-style scenarios the spec calls for** (normal path, returned/rejected path, invalid/edge-case business-rule violation) already exists in the test suite (`WorkflowTest`, `BoundaryTest`) even though the written PDCA narrative document itself is missing — the CHECK-phase evidence exists, it just isn't written up.

---

## 8. Overall conclusion

The **required MVP is functionally complete and correctly enforces the one graded business rule through multiple independent layers**, with all 13 standard demonstration points satisfiable today and a 92/92 passing automated test suite corroborating the workflow, authorization, and visibility claims made in the code.

Two concrete gaps stand between this submission and full deliverable-completeness:

1. **The PDCA reflection is missing entirely** and is explicitly required, not optional.
2. **The diagrams/ERD document is stale** and no longer agrees with the migrations actually submitted, which is a direct violation of an explicit spec instruction ("The ERD should agree with the submitted Laravel migrations").

Neither gap requires touching the working application code — both are documentation-only fixes. Beyond that, the team has substantially over-built relative to the stated "same workload as eight other teams" fairness goal; none of that extra work is broken, but it should not be allowed to crowd out the required flow during judging.
