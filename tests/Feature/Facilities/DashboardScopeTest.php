<?php

namespace Tests\Feature\Facilities;

use App\Filament\Widgets\CategoryVolumeChart;
use App\Filament\Widgets\RecentlyCompletedWidget;
use App\Filament\Widgets\RecentRequestsWidget;
use App\Filament\Widgets\RequestStatusOverview;
use App\Filament\Widgets\RequestTrendChart;
use App\Filament\Widgets\StaffWorkloadWidget;
use App\Models\ServiceCategory;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Services\ServiceRequestWorkflow;
use Filament\Facades\Filament;
use Livewire\Livewire;

class DashboardScopeTest extends FacilitiesTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_operational_dashboard_widgets_are_restricted_to_supervisor_and_super_admin(): void
    {
        $widgets = [RequestStatusOverview::class, CategoryVolumeChart::class, RequestTrendChart::class, StaffWorkloadWidget::class, RecentRequestsWidget::class, RecentlyCompletedWidget::class];

        $this->assertFalse(RequestStatusOverview::isDiscovered());

        foreach (['requester' => false, 'service_staff' => false, 'service_supervisor' => true, 'super_admin' => true] as $role => $canView) {
            $this->actingAs(User::factory()->create()->assignRole($role));

            foreach ($widgets as $widget) {
                $this->assertSame($canView, $widget::canView(), "widget={$widget} role={$role}");
            }
        }
    }

    public function test_request_status_overview_reports_accurate_counts_and_average_resolution_time(): void
    {
        $supervisor = User::factory()->create()->assignRole('service_supervisor');
        $superAdmin = User::factory()->create()->assignRole('super_admin');
        $staff = User::factory()->create()->assignRole('service_staff');
        $requester = User::factory()->create()->assignRole('requester');
        $this->actingAs($supervisor);
        $category = ServiceCategory::factory()->create();
        $workflow = app(ServiceRequestWorkflow::class);

        $start = now();
        $this->travelTo($start);
        $this->actingAs($requester);
        ServiceRequest::factory()->state(['service_category_id' => $category->id])->create();
        $toComplete = ServiceRequest::factory()->state(['service_category_id' => $category->id])->create();

        $this->travelTo($start->copy()->addDay());
        $this->actingAs($supervisor);
        $toComplete = $workflow->assign($toComplete, $staff->id);

        $this->travelTo($start->copy()->addDays(2));
        $this->actingAs($staff);
        $toComplete = $workflow->start($toComplete);

        $this->travelTo($start->copy()->addDays(3));
        $toComplete = $workflow->submitForConfirmation($toComplete, 'Fixed and verified.');

        $this->travelTo($start->copy()->addDays(4));
        $this->actingAs($supervisor);
        $workflow->complete($toComplete);

        $expected = [
            'total' => 2,
            'submitted' => 1,
            'assigned' => 0,
            'in_progress' => 0,
            'for_confirmation' => 0,
            'completed' => 1,
            'average_resolution_days' => 4.0,
        ];
        $this->assertSame($expected, RequestStatusOverview::counts($supervisor));
        $this->assertSame($expected, RequestStatusOverview::counts($superAdmin));

        $this->actingAs($supervisor);
        $overview = Livewire::test(RequestStatusOverview::class);
        $overview->assertSee('Total requests')->assertSee('4.0 days');
        $this->assertStringContainsString('himo-stat-bottom-border-success', $overview->html());
        $this->assertStringContainsString('himo-stat-bottom-border-warning', $overview->html());

        $this->assertSame('full', app(CategoryVolumeChart::class)->getColumnSpan());
        $this->assertSame('full', app(RequestTrendChart::class)->getColumnSpan());
        $this->assertSame('full', app(StaffWorkloadWidget::class)->getColumnSpan());
        $this->assertSame('full', app(RecentRequestsWidget::class)->getColumnSpan());
        $this->assertSame('full', app(RecentlyCompletedWidget::class)->getColumnSpan());
        $this->assertStringContainsString('max-height: 27rem', Livewire::test(CategoryVolumeChart::class)->html());
        $trendHtml = Livewire::test(RequestTrendChart::class)->html();
        foreach (['Volume', 'Assigned', 'Unassigned', 'In progress', 'Completed'] as $dataset) {
            $this->assertStringContainsString($dataset, $trendHtml);
        }

        $outsider = User::factory()->create()->assignRole('requester');
        $this->assertSame(0, RequestStatusOverview::counts($outsider)['total']);
        $this->assertNull(RequestStatusOverview::counts($outsider)['average_resolution_days']);
    }

    public function test_staff_workload_widget_reports_active_and_completed_assignment_counts(): void
    {
        $supervisor = User::factory()->create()->assignRole('service_supervisor');
        $staff = User::factory()->create()->assignRole('service_staff');
        $otherStaff = User::factory()->create()->assignRole('service_staff');
        $requester = User::factory()->create()->assignRole('requester');
        $this->actingAs($supervisor);
        $category = ServiceCategory::factory()->create();
        $workflow = app(ServiceRequestWorkflow::class);

        $this->actingAs($requester);
        // Explicit, non-overlapping windows: both requests go to the same
        // staff member below, and the factory's random schedule could
        // otherwise collide with the assignment conflict check.
        $active = ServiceRequest::factory()->state([
            'service_category_id' => $category->id,
            'needed_start_at' => now()->addDay(),
            'needed_end_at' => now()->addDay()->addHours(2),
        ])->create();
        $completed = ServiceRequest::factory()->state([
            'service_category_id' => $category->id,
            'needed_start_at' => now()->addDays(2),
            'needed_end_at' => now()->addDays(2)->addHours(2),
        ])->create();

        $this->actingAs($supervisor);
        $active = $workflow->assign($active, $staff->id);
        $completed = $workflow->assign($completed, $staff->id);

        $this->actingAs($staff);
        $completed = $workflow->start($completed);
        $completed = $workflow->submitForConfirmation($completed, 'Done.');
        $this->actingAs($supervisor);
        $workflow->complete($completed);

        Livewire::test(StaffWorkloadWidget::class)
            ->assertCanSeeTableRecords([$staff, $otherStaff])
            ->assertTableColumnStateSet('active_count', 1, $staff)
            ->assertTableColumnStateSet('completed_count', 1, $staff)
            ->assertTableColumnStateSet('active_count', 0, $otherStaff)
            ->assertTableColumnStateSet('completed_count', 0, $otherStaff);
    }

    public function test_recent_requests_widget_is_scoped_to_visible_records_and_inaccessible_to_requesters(): void
    {
        $supervisor = User::factory()->create()->assignRole('service_supervisor');
        $requester = User::factory()->create()->assignRole('requester');
        $outsider = User::factory()->create()->assignRole('requester');
        $this->actingAs($supervisor);
        $category = ServiceCategory::factory()->create();

        $this->actingAs($requester);
        $mine = ServiceRequest::factory()->state(['service_category_id' => $category->id])->create();
        $this->actingAs($outsider);
        $theirs = ServiceRequest::factory()->state(['service_category_id' => $category->id])->create();

        $this->actingAs($supervisor);
        Livewire::test(RecentRequestsWidget::class)->assertCanSeeTableRecords([$mine, $theirs]);

        $this->actingAs($requester);
        $this->assertFalse(RecentRequestsWidget::canView());
    }
}
