# QA Assessment — Facilities and Service Request Management

**Assessment date:** 22 September 2026  
**Baseline:** `09_facilities_service_requests_balanced.md`  
**Verdict:** **Functionally compliant with one mandatory deliverable gap.** The core MVP is demonstrably complete and well protected. It should not be presented as fully submission-ready until a short PDCA reflection is added and the PHP runtime mismatch is resolved.

## Evidence and test status

The assessment covered the application code, migrations, models, policies, Filament resources/pages/widgets, seeders/factories, tests, configuration, Docker definition, and the Facilities documentation/diagrams. Generated dependencies, build artefacts, and Git internals were excluded from code-quality review.

| Check | Result | Evidence |
|---|---:|---|
| Full automated suite | Pass | `docker compose exec -T php php artisan test --compact`: **92 passed, 513 assertions** (27.11 s) |
| Working tree integrity | Pass | `git diff --check` returned no errors; working tree was clean before this assessment file |
| Local PHP runtime | Blocked | Host PHP is 8.3.33 while the locked dependencies require PHP >= 8.4.1; local Artisan and Boost setup terminate before bootstrapping |
| Container status inspection | Not verified | `docker compose ps` was denied access to the Docker socket in this environment, although the containerised test command completed |
| Manual/browser acceptance | Not performed | Livewire/HTTP tests cover core UI behavior, but no interactive browser walkthrough or Mermaid rendering was performed in this audit |

## Requirements traceability

| Requirement | Assessment | Evidence / QA finding |
|---|---|---|
| Three roles: Requester, Service Staff, Service Supervisor | Meets | `FacilitiesRoleSeeder`, policies, scoped queries, and role-bound workflow actions implement all three. Super Admin is an additional administrative role. |
| Service Category master data | Meets | Soft-deletable `ServiceCategory`, controlled CRUD resource, active/inactive flag, ownership columns, validation, and authorization are present. |
| Service Request transaction | Meets | `ServiceRequest` captures category, location, description, priority, requested and assigned schedules, status, assignee, completion note, owner and updater. `created_by` is assigned from authentication and is not a form field. |
| Required statuses | Meets | The backed enum and database enum implement Submitted, Assigned, In Progress, For Confirmation, and Completed. |
| End-to-end workflow | Meets | Server-side service transitions are Submitted -> Assigned -> In Progress -> For Confirmation -> Completed, with role/current-state checks and database transactions. |
| Completion rule | Meets, defence in depth | Completion requires an assignee and non-blank note in the model, workflow/policy path, and PostgreSQL check constraint. Direct workflow-field edits are blocked. |
| Review/approval and alternate path | Meets | Requester or Supervisor can complete or return a request for correction; return requires a reason, retains assignment, clears the old note, and returns it to In Progress. Submitted requests may also be cancelled by their owner. |
| Authentication and differentiated access | Meets | Filament authentication plus Spatie roles/permissions and policies. Requester sees owned records; staff see current/historical assignments; Supervisor sees operational records. |
| Visibility/ownership and soft deletes | Meets | Category and request models use ownership tracking and `SoftDeletes`; normal cancellation/deletion is soft delete. User accounts use controlled deactivation to preserve history. |
| Search, filters, clear status | Meets | Request/category tables support search, category/status/priority filters and status badges. |
| Dashboard | Meets | Metrics explicitly show Open, Assigned, In-progress, and Completed request counts; extra role-scoped widgets are present. |
| Accomplishment report by category and status | Meets | `ServiceAccomplishmentReport` is a scoped, filterable output with category and status columns/filters and completion note. It is a detailed table rather than a pre-aggregated summary; this is acceptable for the stated “report/output” minimum, but aggregation/export would make it stronger. |
| Basic audit/history | Meets | Immutable, append-only status history records transition, actor, time, from/to status, and remarks; a read-only relation manager enforces owner-record access. Assignment and selected operational changes have additional append-only logs. |
| Seeded demonstration data | Meets | `DatabaseSeeder` invokes `FacilitiesRoleSeeder` and `HimoDemoDataSeeder`; the latter creates five categories, 126 accounts and 700 workflow-generated requests across all required states. An isolated six-record `FacilitiesDemoSeeder` also supports a focused demo. |
| End-to-end demo scenario | Meets | Demo data and `docs/facilities/demo.md` cover submission, assignment, work, confirmation, completion, invalid direct completion, and return-for-correction. Automated tests exercise the workflow. |
| Four required diagrams | Meets | `docs/facilities/diagrams.md` contains a context diagram, migration-aligned ERD, process flow with decision/alternate path, and Level-0 DFD. |
| Short PDCA reflection | **Does not meet** | The specification requires it. `docs/facilities/demo.md` and `docs/facilities/verification.md` explicitly state that PDCA is excluded/pending approval; no Plan–Do–Check–Act reflection was found. |

## Strengths

- The core business rule is unusually robust for an MVP: UI authorization, workflow service validation, model-event validation, and a PostgreSQL constraint each prevent invalid completion.
- Workflow changes are transactionally locked and status history is written with the request, reducing race conditions and audit gaps.
- Authorization is enforced server-side, not merely by hiding UI controls. The test suite includes direct access/visibility boundary checks.
- The schema agrees with the diagrams in the meaningful core areas. The specification explicitly allows omission of suggested `requested_by`, `Location`, `Assignment`, and `Feedback` entities; ownership via `created_by`, a location string, and an assignment-history model are reasonable MVP choices.
- The 92-test suite covers data foundation, workflow, authorization, reporting/demo behavior, schedule conflicts, availability, task history, UI actions, and user administration.

## Findings and recommended disposition

| Severity | Finding | Risk / recommendation |
|---|---|---|
| High | Required PDCA reflection is absent. | This is an explicit judging deliverable, not optional work. Add a concise 30–60 second Plan–Do–Check–Act section that names the administrative problem, core MVP, three test scenarios, one discovered issue, and the resulting improvement. |
| High (release environment) | The host runtime cannot boot the app: PHP 8.3.33 conflicts with the locked package platform requirement of PHP >= 8.4.1. Composer/Artisan/Boost setup fails locally. | Standardise local/CI runtime on the Docker PHP version or PHP >=8.4.1. Do not rely solely on a previously running container; document the supported setup and verify a fresh install. |
| Medium | The general README is the stock Laravel README and does not explain how to install, configure, run, test, or demo HIMO. | Replace it with project onboarding, required PHP/Docker versions, migration/seed commands, demo accounts, test command, and link to the Facilities demo/diagrams. |
| Medium | The repository retains disconnected legacy `ShieldRoleSeeder` and `DemoUserSeeder` files using `custodian`/`approver` roles from a different case study. | They are not invoked by `DatabaseSeeder`, so this is not a current workflow failure. Remove them or clearly mark/archive them to prevent an operator using the wrong seeder and creating misleading roles/accounts. |
| Medium | The product substantially exceeds the 1.5-day MVP scope: backups/restores, user import/export, calendar scheduling, staffing availability, detailed analytics, 700-record seed generation, and additional audit structures add complexity. | Core requirements work, so this is not a functional nonconformance. For judging, lead the demo with the required flow and avoid allowing bonus features to obscure it. Defer maintenance of optional features unless they have a clear owner and acceptance tests. |
| Low | The “accomplishment report” is a filterable detailed list, not a summarized category × status matrix or printable/exportable report. | It satisfies the stated minimum report/output interpretation. If judges expect “by category and status” aggregation, add a compact grouped total/CSV or print view. |
| Low | Documentation contains mutually confusing historical statements: the demo guide says the general seeder should not be rerun because it references stale seeders, while the current `DatabaseSeeder` already uses the correct Facilities seeders. | Reconcile the guide with current code so demonstration instructions are trustworthy and no longer describe superseded behavior. |

## Overall conclusion

The submitted implementation meets the actual operational MVP: it supports the intended roles, master data, controlled request lifecycle, confirmation/rework path, required completion safeguard, scoped dashboard/report/history, seeded demo data, and four implementation-aligned diagrams. Automated verification is strong and currently green.

The assessment is **conditional pass / not yet fully deliverable-complete** because PDCA is mandatory and explicitly absent. Resolve that item and the PHP environment mismatch before final judging or deployment. The remaining findings are quality, scope, and maintainability improvements rather than blockers to the core service-request workflow.
