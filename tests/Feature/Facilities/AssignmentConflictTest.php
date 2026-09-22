<?php

namespace Tests\Feature\Facilities;

use App\Models\ServiceCategory;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Services\ServiceRequestWorkflow;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Phase 6: existing_start < proposed_end AND existing_end > proposed_start.
 * One staff member, one already-scheduled "anchor" assignment from
 * 2026-01-01 10:00 to 12:00, checked against a range of proposed windows.
 */
class AssignmentConflictTest extends FacilitiesTestCase
{
    private User $requester;

    private User $staff;

    private User $supervisor;

    private ServiceCategory $category;

    private ServiceRequestWorkflow $workflow;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requester = User::factory()->create()->assignRole('requester');
        $this->staff = User::factory()->create()->assignRole('service_staff');
        $this->supervisor = User::factory()->create()->assignRole('service_supervisor');
        $this->actingAs($this->supervisor);
        $this->category = ServiceCategory::factory()->create();
        $this->workflow = app(ServiceRequestWorkflow::class);
    }

    private function anchor(string $status = 'assigned'): ServiceRequest
    {
        $this->actingAs($this->requester);
        $request = ServiceRequest::factory()->create([
            'service_category_id' => $this->category->id,
            'needed_start_at' => '2026-01-01 10:00:00',
            'needed_end_at' => '2026-01-01 12:00:00',
        ]);

        $this->actingAs($this->supervisor);
        $request = $this->workflow->assign($request, $this->staff->id);

        if ($status === 'completed') {
            $this->actingAs($this->staff);
            $request = $this->workflow->start($request);
            $request = $this->workflow->submitForConfirmation($request, 'Done.');
            $this->actingAs($this->requester);
            $request = $this->workflow->complete($request);
        } elseif ($status === 'cancelled') {
            // ServiceRequestPolicy only allows cancellation while status is
            // Submitted (before any assignment exists), so "an assigned,
            // scheduled request gets cancelled" can never actually occur
            // through the app today — soft-delete at the DB level directly
            // to test that the conflict query's soft-delete exclusion
            // itself works correctly, independent of whether the workflow
            // can currently reach this state.
            DB::table('service_requests')->where('id', $request->id)->update(['deleted_at' => now()]);
        }

        return $request->fresh();
    }

    private function proposeWindow(string $start, string $end): ServiceRequest
    {
        $this->actingAs($this->requester);
        $request = ServiceRequest::factory()->create([
            'service_category_id' => $this->category->id,
            'needed_start_at' => $start,
            'needed_end_at' => $end,
        ]);
        $this->actingAs($this->supervisor);

        return $this->workflow->assign($request, $this->staff->id);
    }

    public function test_proposed_window_starting_exactly_when_the_existing_one_ends_does_not_conflict(): void
    {
        $this->anchor();

        $request = $this->proposeWindow('2026-01-01 12:00:00', '2026-01-01 13:00:00');

        $this->assertSame($this->staff->id, $request->assigned_to);
    }

    public function test_proposed_window_ending_exactly_when_the_existing_one_starts_does_not_conflict(): void
    {
        $this->anchor();

        $request = $this->proposeWindow('2026-01-01 09:00:00', '2026-01-01 10:00:00');

        $this->assertSame($this->staff->id, $request->assigned_to);
    }

    public function test_proposed_window_overlapping_the_end_of_the_existing_one_is_rejected(): void
    {
        $this->anchor();

        $this->expectException(ValidationException::class);
        $this->proposeWindow('2026-01-01 11:00:00', '2026-01-01 13:00:00');
    }

    public function test_proposed_window_overlapping_the_start_of_the_existing_one_is_rejected(): void
    {
        $this->anchor();

        $this->expectException(ValidationException::class);
        $this->proposeWindow('2026-01-01 09:00:00', '2026-01-01 11:00:00');
    }

    public function test_proposed_window_fully_containing_the_existing_one_is_rejected(): void
    {
        $this->anchor();

        $this->expectException(ValidationException::class);
        $this->proposeWindow('2026-01-01 09:00:00', '2026-01-01 13:00:00');
    }

    public function test_proposed_window_fully_inside_the_existing_one_is_rejected(): void
    {
        $this->anchor();

        $this->expectException(ValidationException::class);
        $this->proposeWindow('2026-01-01 10:30:00', '2026-01-01 11:30:00');
    }

    public function test_identical_window_is_rejected(): void
    {
        $this->anchor();

        $this->expectException(ValidationException::class);
        $this->proposeWindow('2026-01-01 10:00:00', '2026-01-01 12:00:00');
    }

    public function test_completed_assignment_releases_the_schedule(): void
    {
        $this->anchor(status: 'completed');

        $request = $this->proposeWindow('2026-01-01 10:30:00', '2026-01-01 11:30:00');

        $this->assertSame($this->staff->id, $request->assigned_to);
    }

    public function test_cancelled_request_releases_the_schedule(): void
    {
        $this->anchor(status: 'cancelled');

        $request = $this->proposeWindow('2026-01-01 10:30:00', '2026-01-01 11:30:00');

        $this->assertSame($this->staff->id, $request->assigned_to);
    }

    public function test_an_elapsed_assignment_does_not_block_a_future_one(): void
    {
        $this->anchor(); // Still "assigned" (never completed/cancelled), but its window is now in the past.

        $request = $this->proposeWindow('2026-06-01 10:00:00', '2026-06-01 12:00:00');

        $this->assertSame($this->staff->id, $request->assigned_to);
    }

    public function test_conflict_message_identifies_the_overlapping_window_and_request_number(): void
    {
        $anchor = $this->anchor();

        try {
            $this->proposeWindow('2026-01-01 11:00:00', '2026-01-01 13:00:00');
            $this->fail('Overlapping assignment was accepted.');
        } catch (ValidationException $exception) {
            $message = $exception->errors()['assigned_to'][0];
            $this->assertStringContainsString($anchor->request_no, $message);
            $this->assertStringContainsString('10:00', $message);
            $this->assertStringContainsString('12:00', $message);
        }
    }

    public function test_a_second_conflicting_assignment_is_rejected_after_the_first_has_already_committed(): void
    {
        // True concurrent-process locking isn't reachable from a single
        // PHPUnit connection; this proves the check is re-enforced against
        // already-committed state rather than only client-side/stale data,
        // which is what the row lock inside assign() protects in production.
        $this->anchor();

        $this->expectException(ValidationException::class);
        $this->proposeWindow('2026-01-01 10:30:00', '2026-01-01 11:30:00');
    }
}
