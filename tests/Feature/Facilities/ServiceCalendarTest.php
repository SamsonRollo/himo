<?php

namespace Tests\Feature\Facilities;

use App\Filament\Pages\ServiceCalendar;
use App\Models\ServiceCategory;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Services\ServiceRequestWorkflow;
use Filament\Facades\Filament;
use Livewire\Livewire;

class ServiceCalendarTest extends FacilitiesTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_supervisor_and_super_admin_can_access_the_calendar_and_manage_the_staff_view(): void
    {
        foreach (['service_supervisor', 'super_admin'] as $role) {
            $user = User::factory()->create()->assignRole($role);
            $this->actingAs($user);

            $this->assertTrue(ServiceCalendar::canAccess());
            $this->get(ServiceCalendar::getUrl())->assertOk();
            $this->assertTrue(Livewire::test(ServiceCalendar::class)->instance()->canManageStaffView());
        }
    }

    public function test_requester_cannot_access_the_calendar(): void
    {
        $this->actingAs(User::factory()->create()->assignRole('requester'));

        $this->assertFalse(ServiceCalendar::canAccess());
        $this->get(ServiceCalendar::getUrl())->assertForbidden();
    }

    public function test_service_staff_can_access_the_calendar_but_cannot_manage_the_staff_view(): void
    {
        $this->actingAs(User::factory()->create()->assignRole('service_staff'));

        $this->assertTrue(ServiceCalendar::canAccess());
        $this->get(ServiceCalendar::getUrl())->assertOk();
        $this->assertFalse(Livewire::test(ServiceCalendar::class)->instance()->canManageStaffView());
    }

    public function test_service_staff_only_sees_their_own_scheduled_events(): void
    {
        $requester = User::factory()->create()->assignRole('requester');
        $staff = User::factory()->create()->assignRole('service_staff');
        $otherStaff = User::factory()->create()->assignRole('service_staff');
        $supervisor = User::factory()->create()->assignRole('service_supervisor');
        $this->actingAs($requester);
        $mine = ServiceRequest::factory()->create();
        $theirs = ServiceRequest::factory()->create();

        $this->actingAs($supervisor);
        app(ServiceRequestWorkflow::class)->assign($mine, $staff->id);
        app(ServiceRequestWorkflow::class)->assign($theirs, $otherStaff->id);

        $this->actingAs($staff);
        $events = Livewire::test(ServiceCalendar::class)->instance()->getCalendarEvents();

        $this->assertCount(1, $events);
        $this->assertSame($mine->id, $events[0]['id']);
    }

    public function test_supervisor_sees_every_scheduled_request_and_can_filter_by_staff(): void
    {
        $requester = User::factory()->create()->assignRole('requester');
        $staff = User::factory()->create()->assignRole('service_staff');
        $otherStaff = User::factory()->create()->assignRole('service_staff');
        $supervisor = User::factory()->create()->assignRole('service_supervisor');
        $this->actingAs($requester);
        $forStaff = ServiceRequest::factory()->create();
        $forOtherStaff = ServiceRequest::factory()->create();

        $this->actingAs($supervisor);
        app(ServiceRequestWorkflow::class)->assign($forStaff, $staff->id);
        app(ServiceRequestWorkflow::class)->assign($forOtherStaff, $otherStaff->id);

        $component = Livewire::test(ServiceCalendar::class);
        $this->assertCount(2, $component->instance()->getCalendarEvents());

        $component->set('staffFilter', $staff->id);
        $filtered = $component->instance()->getCalendarEvents();
        $this->assertCount(1, $filtered);
        $this->assertSame($forStaff->id, $filtered[0]['id']);
    }

    public function test_staff_view_shows_nothing_until_a_staff_member_is_selected(): void
    {
        $requester = User::factory()->create()->assignRole('requester');
        $staff = User::factory()->create()->assignRole('service_staff');
        $supervisor = User::factory()->create()->assignRole('service_supervisor');
        $this->actingAs($requester);
        $request = ServiceRequest::factory()->create();
        $this->actingAs($supervisor);
        app(ServiceRequestWorkflow::class)->assign($request, $staff->id);

        $component = Livewire::test(ServiceCalendar::class)->set('viewMode', 'staff');
        $this->assertCount(0, $component->instance()->getCalendarEvents());

        $component->set('staffFilter', $staff->id);
        $this->assertCount(1, $component->instance()->getCalendarEvents());
    }

    public function test_unscheduled_requests_are_excluded_from_the_calendar(): void
    {
        $requester = User::factory()->create()->assignRole('requester');
        $supervisor = User::factory()->create()->assignRole('service_supervisor');
        $this->actingAs($requester);
        ServiceCategory::factory()->create();
        ServiceRequest::factory()->create(); // Submitted, never assigned/scheduled.

        $this->actingAs($supervisor);
        $this->assertCount(0, Livewire::test(ServiceCalendar::class)->instance()->getCalendarEvents());
    }
}
