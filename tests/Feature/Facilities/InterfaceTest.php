<?php

namespace Tests\Feature\Facilities;

use App\Enums\ServiceRequestStatus as Status;
use App\Filament\Resources\ServiceRequests\Pages\CreateServiceRequest;
use App\Filament\Resources\ServiceRequests\Pages\ListServiceRequests;
use App\Models\ServiceCategory;
use App\Models\ServiceRequest;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;

class InterfaceTest extends FacilitiesTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_request_creation_and_complete_workflow_through_filament_actions(): void
    {
        $owner = User::factory()->create()->assignRole('requester');
        $staff = User::factory()->create()->assignRole('service_staff');
        $supervisor = User::factory()->create()->assignRole('service_supervisor');
        $this->actingAs($owner);
        $category = ServiceCategory::factory()->create();
        Livewire::test(CreateServiceRequest::class)
            ->fillForm([
                'service_category_id' => $category->id,
                'location' => 'Room 101',
                'description' => 'Leaking tap',
                'needed_start_at' => now()->addDay(),
                'needed_end_at' => now()->addDay()->addHours(2),
            ])
            ->call('create')->assertHasNoFormErrors();
        $request = ServiceRequest::sole();
        $this->assertSame($owner->id, $request->created_by);

        $this->actingAs($supervisor);
        Livewire::test(ListServiceRequests::class)->callTableAction('assign', $request, ['assigned_to' => $staff->id])->assertHasNoTableActionErrors();
        $this->actingAs($staff);
        Livewire::test(ListServiceRequests::class)->callTableAction('start', $request)->assertHasNoTableActionErrors();
        Livewire::test(ListServiceRequests::class)->callTableAction('submitForConfirmation', $request, ['completion_note' => 'Tap replaced and tested.'])->assertHasNoTableActionErrors();
        $this->actingAs($owner);
        Livewire::test(ListServiceRequests::class)->callTableAction('complete', $request)->assertHasNoTableActionErrors();
        $this->assertSame(Status::Completed, $request->fresh()->status);
        $this->assertSame(5, $request->statusHistories()->count());
    }

    public function test_search_filters_and_record_urls_do_not_expose_other_requesters_records(): void
    {
        $owner = User::factory()->create()->assignRole('requester');
        $this->actingAs($owner);
        $mine = ServiceRequest::factory()->create(['location' => 'Library']);
        $this->actingAs(User::factory()->create()->assignRole('requester'));
        $other = ServiceRequest::factory()->create();
        $this->actingAs($owner);

        Livewire::test(ListServiceRequests::class)->assertCanSeeTableRecords([$mine])->assertCanNotSeeTableRecords([$other])
            ->searchTable('Library')->assertCanSeeTableRecords([$mine])
            ->filterTable('status', Status::Completed->value)->assertCanNotSeeTableRecords([$mine]);
        $this->get('/admin/service-requests/'.$other->id)->assertNotFound();
        $this->get('/admin/service-requests/'.$mine->id)->assertOk();
    }
}
