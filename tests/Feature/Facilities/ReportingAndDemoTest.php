<?php

namespace Tests\Feature\Facilities;

use App\Filament\Pages\ServiceAccomplishmentReport;
use App\Filament\Resources\ServiceRequests\Pages\ViewServiceRequest;
use App\Filament\Resources\ServiceRequests\RelationManagers\StatusHistoriesRelationManager;
use App\Filament\Widgets\ServiceRequestMetrics;
use App\Models\ServiceRequest;
use App\Models\User;
use Database\Seeders\FacilitiesDemoSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

class ReportingAndDemoTest extends FacilitiesTestCase
{
    public function test_demo_is_idempotent_and_metrics_report_and_history_match_records(): void
    {
        $this->seed(FacilitiesDemoSeeder::class);
        $passwords = User::pluck('password', 'id')->all();
        $this->seed(FacilitiesDemoSeeder::class);
        $this->assertSame($passwords, User::pluck('password', 'id')->all());
        $this->assertSame(6, ServiceRequest::count());
        $supervisor = User::role('service_supervisor')->sole();
        $this->actingAs($supervisor);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->assertSame(['Open requests' => 5, 'Assigned requests' => 1, 'In-progress requests' => 2, 'Completed requests' => 1], ServiceRequestMetrics::counts($supervisor));
        Livewire::test(ServiceRequestMetrics::class)->assertSee('Open requests')->assertSee('Completed requests');
        $completed = ServiceRequest::where('status', 'completed')->sole();
        $submitted = ServiceRequest::where('status', 'submitted')->sole();
        Livewire::test(ServiceAccomplishmentReport::class)->filterTable('status', 'completed')
            ->assertCanSeeTableRecords([$completed])->assertCanNotSeeTableRecords([$submitted])
            ->filterTable('service_category_id', $submitted->service_category_id)
            ->assertCanSeeTableRecords([$completed]);
        Livewire::test(StatusHistoriesRelationManager::class, ['ownerRecord' => $completed, 'pageClass' => ViewServiceRequest::class])
            ->assertCanSeeTableRecords($completed->statusHistories);

        $outsider = User::factory()->create()->assignRole('requester');
        $this->actingAs($outsider);
        $this->assertSame(0, array_sum(ServiceRequestMetrics::counts($outsider)));
        Livewire::test(ServiceAccomplishmentReport::class)->assertCanNotSeeTableRecords(ServiceRequest::all());
        Livewire::test(StatusHistoriesRelationManager::class, ['ownerRecord' => $completed, 'pageClass' => ViewServiceRequest::class])->assertForbidden();
    }
}
