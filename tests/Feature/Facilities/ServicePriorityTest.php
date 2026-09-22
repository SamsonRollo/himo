<?php

namespace Tests\Feature\Facilities;

use App\Enums\ServiceRequestPriority as Priority;
use App\Filament\Resources\ServiceRequests\Pages\ListServiceRequests;
use App\Models\ServiceCategory;
use App\Models\ServiceRequest;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;

class ServicePriorityTest extends FacilitiesTestCase
{
    public function test_priority_defaults_to_normal_when_not_specified(): void
    {
        $this->actingAs(User::factory()->create()->assignRole('requester'));

        $request = ServiceRequest::factory()->create();

        $this->assertSame(Priority::Normal, $request->priority);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function validPriorities(): array
    {
        return [
            'low' => ['low'],
            'normal' => ['normal'],
            'high' => ['high'],
            'urgent' => ['urgent'],
            'critical' => ['critical'],
        ];
    }

    #[DataProvider('validPriorities')]
    public function test_each_valid_priority_value_is_accepted(string $value): void
    {
        $this->actingAs(User::factory()->create()->assignRole('requester'));

        $request = ServiceRequest::factory()->create(['priority' => $value]);

        $this->assertSame($value, $request->priority->value);
    }

    public function test_invalid_priority_value_is_rejected(): void
    {
        $this->actingAs(User::factory()->create()->assignRole('requester'));

        $this->expectException(\ValueError::class);
        ServiceRequest::factory()->create(['priority' => 'not-a-real-priority']);
    }

    public function test_priority_filter_narrows_the_request_list(): void
    {
        $requester = User::factory()->create()->assignRole('requester');
        $this->actingAs($requester);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $category = ServiceCategory::factory()->create();
        $urgent = ServiceRequest::factory()->create(['service_category_id' => $category->id, 'priority' => 'urgent']);
        $low = ServiceRequest::factory()->create(['service_category_id' => $category->id, 'priority' => 'low']);

        Livewire::test(ListServiceRequests::class)
            ->assertCanSeeTableRecords([$urgent, $low])
            ->filterTable('priority', 'urgent')
            ->assertCanSeeTableRecords([$urgent])
            ->assertCanNotSeeTableRecords([$low]);
    }
}
