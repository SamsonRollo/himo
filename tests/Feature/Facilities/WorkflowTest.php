<?php

namespace Tests\Feature\Facilities;

use App\Enums\ServiceRequestStatus as Status;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Services\ServiceRequestWorkflow;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

class WorkflowTest extends FacilitiesTestCase
{
    private function actors(): array
    {
        return [
            User::factory()->create()->assignRole('requester'),
            User::factory()->create()->assignRole('service_staff'),
            User::factory()->create()->assignRole('service_supervisor'),
        ];
    }

    public function test_full_workflow_and_return_path_record_the_authenticated_actors(): void
    {
        [$owner, $staff, $supervisor] = $this->actors();
        $workflow = app(ServiceRequestWorkflow::class);
        $this->actingAs($owner);
        $request = ServiceRequest::factory()->create();
        $this->actingAs($supervisor);
        $request = $workflow->assign($request, $staff->id);
        $this->actingAs($staff);
        $request = $workflow->start($request);
        $request = $workflow->updateWork($request, 'Investigated the leak.');
        $request = $workflow->submitForConfirmation($request, 'Replaced washer.');
        $this->actingAs($owner);
        $request = $workflow->returnForCorrection($request, 'Still leaking.');
        $this->assertNull($request->completion_note);
        $this->assertSame($staff->id, $request->assigned_to);
        $this->actingAs($staff);
        $request = $workflow->submitForConfirmation($request, 'Replaced valve and tested.');
        $this->actingAs($owner);
        $request = $workflow->complete($request);

        $this->assertSame(Status::Completed, $request->status);
        $this->assertNotNull($request->completed_at);
        $history = $request->statusHistories()->get();
        $this->assertCount(7, $history);
        $this->assertSame([null, 'submitted', 'assigned', 'in_progress', 'for_confirmation', 'in_progress', 'for_confirmation'], $history->map(fn ($row) => $row->from_status?->value)->all());
        $this->assertSame([$owner->id, $supervisor->id, $staff->id, $staff->id, $owner->id, $staff->id, $owner->id], $history->pluck('changed_by')->all());
        $this->assertSame('Still leaking.', $history[4]->remarks);
        $this->assertSame($owner->id, $request->updated_by);
    }

    public function test_direct_completion_is_rejected_even_with_assignment_and_note(): void
    {
        [$owner, $staff] = $this->actors();
        $this->actingAs($owner);
        $request = ServiceRequest::factory()->create();
        $request->forceFill(['status' => Status::Completed, 'assigned_to' => $staff->id, 'completion_note' => 'Bypass']);
        try {
            $request->save();
            $this->fail('Direct completion was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('status', $exception->errors());
        }
        $this->assertSame(Status::Submitted, $request->fresh()->status);
        $this->assertSame(1, $request->statusHistories()->count());
    }

    public function test_wrong_actor_and_stale_transition_are_rejected(): void
    {
        [$owner, $staff, $supervisor] = $this->actors();
        $this->actingAs($owner);
        $request = ServiceRequest::factory()->create();
        $workflow = app(ServiceRequestWorkflow::class);
        try {
            $workflow->assign($request, $staff->id);
            $this->fail('Requester assigned a request.');
        } catch (AuthorizationException) {
            $this->assertSame(Status::Submitted, $request->fresh()->status);
        }
        $this->actingAs($supervisor);
        $workflow->assign($request, $staff->id);
        $this->expectException(AuthorizationException::class);
        $workflow->assign($request, $staff->id);
    }

    public function test_blank_confirmation_note_and_return_reason_are_rejected(): void
    {
        [$owner, $staff, $supervisor] = $this->actors();
        $workflow = app(ServiceRequestWorkflow::class);
        $this->actingAs($owner);
        $request = ServiceRequest::factory()->create();
        $this->actingAs($supervisor);
        $request = $workflow->assign($request, $staff->id);
        $this->actingAs($staff);
        $request = $workflow->start($request);
        try {
            $workflow->submitForConfirmation($request, " \n\t ");
            $this->fail('Blank note accepted.');
        } catch (ValidationException) {
            $this->assertSame(Status::InProgress, $request->fresh()->status);
        }
        $request = $workflow->submitForConfirmation($request, 'Fixed.');
        $this->actingAs($supervisor);
        try {
            $workflow->returnForCorrection($request, '  ');
            $this->fail('Blank reason accepted.');
        } catch (ValidationException) {
            $this->assertSame(Status::ForConfirmation, $request->fresh()->status);
        }
        $this->assertSame(Status::Completed, $workflow->complete($request)->status);
    }

    public function test_unassigned_request_cannot_jump_to_completed(): void
    {
        [$owner] = $this->actors();
        $this->actingAs($owner);
        $request = ServiceRequest::factory()->create();
        $this->expectException(AuthorizationException::class);
        app(ServiceRequestWorkflow::class)->complete($request);
    }
}
