<?php

namespace Tests\Feature\Facilities;

use App\Filament\Resources\ServiceRequests\Pages\ListServiceRequests;
use App\Models\ServiceRequest;
use App\Models\ServiceRequestAssignment;
use App\Models\User;
use App\Services\ServiceRequestWorkflow;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * Case study requirement 3: Service Staff need Active vs Historical
 * assignment views, and history must survive the assigned_to column
 * changing — not just reflect its current value.
 */
class TaskHistoryTest extends FacilitiesTestCase
{
    public function test_assign_writes_an_append_only_assignment_log_row(): void
    {
        $owner = User::factory()->create()->assignRole('requester');
        $staff = User::factory()->create()->assignRole('service_staff');
        $supervisor = User::factory()->create()->assignRole('service_supervisor');

        $this->actingAs($owner);
        $request = ServiceRequest::factory()->create();
        $this->actingAs($supervisor);
        app(ServiceRequestWorkflow::class)->assign($request, $staff->id);

        $this->assertSame(1, ServiceRequestAssignment::count());
        $log = ServiceRequestAssignment::sole();
        $this->assertSame($request->id, $log->service_request_id);
        $this->assertSame($staff->id, $log->staff_id);
        $this->assertSame($supervisor->id, $log->assigned_by);
    }

    public function test_active_and_history_tabs_split_by_completion_for_staff(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $owner = User::factory()->create()->assignRole('requester');
        $staff = User::factory()->create()->assignRole('service_staff');
        $supervisor = User::factory()->create()->assignRole('service_supervisor');
        $workflow = app(ServiceRequestWorkflow::class);

        $this->actingAs($owner);
        // Explicit, non-overlapping windows: both go to the same staff
        // member below, and the factory's random default schedule could
        // otherwise collide with the assignment conflict check.
        $active = ServiceRequest::factory()->create(['needed_start_at' => now()->addDay(), 'needed_end_at' => now()->addDay()->addHours(2)]);
        $completed = ServiceRequest::factory()->create(['needed_start_at' => now()->addDays(2), 'needed_end_at' => now()->addDays(2)->addHours(2)]);

        $this->actingAs($supervisor);
        $active = $workflow->assign($active, $staff->id);
        $completed = $workflow->assign($completed, $staff->id);

        $this->actingAs($staff);
        $completed = $workflow->start($completed);
        $completed = $workflow->submitForConfirmation($completed, 'Done.');
        $this->actingAs($supervisor);
        $completed = $workflow->complete($completed);

        $this->actingAs($staff);
        $list = Livewire::test(ListServiceRequests::class);

        $list->set('activeTab', 'active');
        $list->assertCanSeeTableRecords([$active])->assertCanNotSeeTableRecords([$completed]);

        $list->set('activeTab', 'history');
        $list->assertCanSeeTableRecords([$completed])->assertCanNotSeeTableRecords([$active]);
    }

    public function test_resource_tabs_render_once_inside_the_service_request_table_header(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create()->assignRole('requester'));

        $html = Livewire::test(ListServiceRequests::class)->html();

        $tableMainPosition = strpos($html, 'fi-ta-main');
        $tableHeaderPosition = strpos($html, 'fi-ta-header-ctn');
        $tabsPosition = strpos($html, 'fi-sc-tabs');

        $this->assertNotFalse($tableMainPosition);
        $this->assertNotFalse($tableHeaderPosition);
        $this->assertNotFalse($tabsPosition);
        $this->assertLessThan($tabsPosition, $tableHeaderPosition);
        $this->assertLessThan($tableHeaderPosition, $tableMainPosition);
    }
}
