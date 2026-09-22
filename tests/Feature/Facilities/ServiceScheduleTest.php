<?php

namespace Tests\Feature\Facilities;

use App\Models\ActivityLog;
use App\Models\ServiceCategory;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Services\ServiceRequestWorkflow;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ServiceScheduleTest extends FacilitiesTestCase
{
    public function test_needed_schedule_is_required_for_a_new_request(): void
    {
        $this->actingAs(User::factory()->create()->assignRole('requester'));
        $category = ServiceCategory::factory()->create();

        try {
            app(ServiceRequestWorkflow::class)->submit([
                'service_category_id' => $category->id,
                'location' => 'Room 1',
                'description' => 'No schedule supplied.',
            ]);
            $this->fail('Request was submitted without a needed schedule.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('needed_start_at', $exception->errors());
        }
    }

    public function test_needed_end_must_be_later_than_needed_start(): void
    {
        $this->actingAs(User::factory()->create()->assignRole('requester'));
        $category = ServiceCategory::factory()->create();
        $start = now()->addDay();

        try {
            app(ServiceRequestWorkflow::class)->submit([
                'service_category_id' => $category->id,
                'location' => 'Room 1',
                'description' => 'Inverted window.',
                'needed_start_at' => $start,
                'needed_end_at' => $start->copy()->subHour(),
            ]);
            $this->fail('Request was submitted with needed_end_at before needed_start_at.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('needed_end_at', $exception->errors());
        }
    }

    public function test_assignment_defaults_the_scheduled_window_to_the_requested_window(): void
    {
        $requester = User::factory()->create()->assignRole('requester');
        $staff = User::factory()->create()->assignRole('service_staff');
        $supervisor = User::factory()->create()->assignRole('service_supervisor');
        $this->actingAs($requester);
        $request = ServiceRequest::factory()->create();

        $this->actingAs($supervisor);
        $request = app(ServiceRequestWorkflow::class)->assign($request, $staff->id);

        $this->assertTrue($request->scheduled_start_at->equalTo($request->needed_start_at));
        $this->assertTrue($request->scheduled_end_at->equalTo($request->needed_end_at));
        $this->assertSame(0, ActivityLog::where('action', 'schedule_overridden')->count());
    }

    public function test_supervisor_can_override_the_scheduled_window_at_assignment_and_it_is_logged(): void
    {
        $requester = User::factory()->create()->assignRole('requester');
        $staff = User::factory()->create()->assignRole('service_staff');
        $supervisor = User::factory()->create()->assignRole('service_supervisor');
        $this->actingAs($requester);
        $request = ServiceRequest::factory()->create();
        $originalNeededStart = $request->needed_start_at;

        // Datetime columns store second precision, so drop sub-second noise
        // before round-tripping through the database for comparison.
        $override = now()->addDays(5)->startOfSecond();
        $this->actingAs($supervisor);
        $request = app(ServiceRequestWorkflow::class)->assign($request, $staff->id, $override, $override->copy()->addHours(3));

        $this->assertTrue($request->scheduled_start_at->equalTo($override));
        // The requester's original ask is untouched, not silently overwritten.
        $this->assertTrue($request->fresh()->needed_start_at->equalTo($originalNeededStart));

        $log = ActivityLog::where('subject_type', $request->getMorphClass())->where('subject_id', $request->id)->where('action', 'schedule_overridden')->sole();
        $this->assertSame($supervisor->id, $log->changed_by);
        $this->assertStringContainsString($override->toIso8601String(), $log->to_value);
    }

    public function test_assignment_without_any_resolvable_schedule_is_rejected(): void
    {
        $requester = User::factory()->create()->assignRole('requester');
        $staff = User::factory()->create()->assignRole('service_staff');
        $supervisor = User::factory()->create()->assignRole('service_supervisor');
        $this->actingAs($requester);
        $request = ServiceRequest::factory()->create();
        // Simulate a request seeded before this field existed: bypass
        // Eloquent (which requires needed_* for new records) to null it out
        // on an already-persisted row, the only way this state can occur.
        DB::table('service_requests')->where('id', $request->id)->update(['needed_start_at' => null, 'needed_end_at' => null]);
        $request = $request->fresh();

        $this->actingAs($supervisor);
        $this->expectException(ValidationException::class);
        app(ServiceRequestWorkflow::class)->assign($request, $staff->id);
    }

    public function test_editing_the_scheduled_window_outside_the_workflow_is_rejected(): void
    {
        $requester = User::factory()->create()->assignRole('requester');
        $staff = User::factory()->create()->assignRole('service_staff');
        $supervisor = User::factory()->create()->assignRole('service_supervisor');
        $this->actingAs($requester);
        $request = ServiceRequest::factory()->create();
        $this->actingAs($supervisor);
        $request = app(ServiceRequestWorkflow::class)->assign($request, $staff->id);

        $request->scheduled_start_at = now()->addMonth();
        $this->expectException(ValidationException::class);
        $request->save();
    }
}
