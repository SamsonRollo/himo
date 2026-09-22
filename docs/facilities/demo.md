# Facilities MVP demonstration

## Existing runtime and setup

Use the existing running Docker services. No dependency or stack updates are needed. Apply pending additive migrations and run the isolated facilities seeders:

```sh
docker compose exec -T php php artisan migrate --force
docker compose exec -T php php artisan db:seed --class=FacilitiesDemoSeeder --force
```

Do not rerun the general `DatabaseSeeder` for this demo: its pre-existing `DemoUserSeeder` updates existing demo account passwords and roles. `FacilitiesDemoSeeder` calls only the additive `FacilitiesRoleSeeder`, preserves existing matching accounts, and does not reset existing demo requests. It refuses to run in production or overwrite an account with a conflicting role. The data is local demonstration data, not production credentials.

Open `/admin/login` using the application's configured host. New demonstration accounts all use the deliberately obvious local-only password `facilities-demo-only`:

| Role | Email |
|---|---|
| Requester | `facilities-requester@example.test` |
| Service Staff | `facilities-service_staff@example.test` |
| Service Supervisor | `facilities-service_supervisor@example.test` |

The seed creates Demo Electrical and Demo Plumbing categories plus six requests: one Submitted, one Assigned, two In Progress (one returned for correction), one For Confirmation, and one Completed. Generated IDs, request numbers and timestamps are runtime values; scenario contents and status counts are deterministic. Re-running the seeder without editing those demo descriptions adds no duplicate requests and changes no passwords. If a demo record is subsequently changed, seeding does not reset its workflow. Soft-deleted demo requests are not recreated.

## Complete scenario

1. Sign in as Service Supervisor. In **Service Categories**, create an active category, or use an existing demo category. A requester cannot create or edit categories.
2. Sign in as Requester. In **Service Requests**, create a request for `Room 101`, description `Tap leaking at the wash basin`, using Demo Plumbing. The request starts Submitted. Record its generated request number. The creator is automatic and has no form control.
3. Sign in as Service Supervisor. Search for the request number and choose **Assign**, selecting Demo Service Staff. Status becomes Assigned.
4. Sign in as Service Staff. Only assigned requests are listed. Open the request and choose **Start work**. Status becomes In Progress. Use **Update work note** to record work, then **For confirmation** with `Replaced tap washer and tested for leaks.`
5. Sign in as the owning Requester (or Service Supervisor), open the request and choose **Complete**. Status becomes Completed and the completion time appears.
6. Open **Status history** on the request. Verify Submitted → Assigned → In Progress → For Confirmation → Completed, with actors and timestamps.
7. Open **Service accomplishment report**, filter by Demo Plumbing and Completed, and locate the completed request and its note. Check the dashboard's four counts. Counts reflect only records visible to the signed-in user; Open includes every non-completed request, including For Confirmation.

The untouched seed's Requester/Supervisor counts are Open 5, Assigned 1, In-progress 2, Completed 1. Service Staff sees Open 4, Assigned 1, In-progress 2, Completed 1. Counts change as the demo is performed.

## Required alternate path and invalid completion

For a request awaiting confirmation, the owning Requester or Supervisor chooses **Return for correction**, supplies a reason, and verifies that status returns to In Progress with the same staff assignment. The reason appears in history and the old completion note is cleared. Staff must enter a new note before resubmitting for confirmation.

A Submitted request has no Complete action. The server also rejects direct completion attempts, including an attempt supplying assignment and a note while bypassing the workflow. Demonstrate the executable checks:

```sh
docker compose exec -T php php vendor/bin/phpunit --do-not-cache-result --filter 'test_unassigned_request_cannot_jump_to_completed|test_direct_completion_is_rejected_even_with_assignment_and_note|test_completion_cannot_be_saved_without_assignment_and_note|test_blank_confirmation_note_and_return_reason_are_rejected'
```

The full interface scenario is exercised by `InterfaceTest::test_request_creation_and_complete_workflow_through_filament_actions`. Tests use newly created SQLite in-memory databases and ordinary additive `migrate`, never an existing application database reset. PostgreSQL additionally enforces the assignment/note completion constraint in the actual application database.

## Access and deletion rules

Requesters see their own requests, staff see assigned requests, and supervisors see all. Existing super admins retain administrative access while still following workflow rules. Existing custodian/approver/panel_user roles are preserved and receive no facilities permissions automatically. The report, dashboard, request URLs and history all use server-side visibility checks.

An owner can edit or soft-delete only a Submitted request. Supervisors manage categories; category deletion preserves references on existing requests. Normal lists exclude deleted records. No restore or permanent-delete UI is provided. Status history is read-only.

## Verification commands

```sh
docker compose exec -T php php vendor/bin/phpunit --do-not-cache-result
docker compose exec -T php php artisan migrate:status
docker compose exec -T php php artisan route:list --path=admin
docker compose exec -T php npm run build
docker compose ps
```

See [the four implemented diagrams](diagrams.md). PDCA and optional/bonus deliverables are excluded pending separate approval.

## RBAC, user management, and backup/restore checklist

These were written without access to a live database or Docker in the implementing sandbox (see `edit_history.md`) and must be exercised manually here before being trusted.

1. **Service Categories are invisible to Requester/Service Staff.** Sign in as `facilities-requester@example.test` and `facilities-service_staff@example.test`; confirm neither sees "Service Categories" in the nav, and that `GET /admin/service-categories` returns 403 for both.
2. **Cancel request.** As a Requester, submit a request, then use "Cancel request" on it while Submitted; confirm it disappears from the list (soft-deleted) and that the action is absent once the request is Assigned.
3. **Task history tabs.** As Service Staff, open Service Requests; confirm "Active"/"History" tabs, with completed assignments only under History.
4. **User management.** As `admin@example.com`: create a user, deactivate them (confirm they can no longer log in but their past requests still display their name), reactivate them. Confirm a non-super-admin gets 403 on `/admin/users`.
5. **CSV export/import.** Export users to CSV from the Users list. Re-import it with "Update existing accounts" checked and confirm no duplicates are created; try a row with `role=super_admin` and confirm it's rejected.
6. **Backup and restore — do this against disposable/demo data only.** From "Backups" (Super Admin), click "Create backup now" and confirm a row appears with a nonzero size. Download it. Click "Restore", confirm typing the wrong filename is rejected, then confirm restoring with the correct filename succeeds and the app is briefly in maintenance mode during the operation. Confirm a `SystemRestore` audit row was written either way.
