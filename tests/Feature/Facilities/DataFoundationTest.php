<?php

namespace Tests\Feature\Facilities;

use App\Enums\ServiceRequestStatus;
use App\Models\ServiceCategory;
use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class DataFoundationTest extends FacilitiesTestCase
{
    public function test_authenticated_ownership_relationships_and_soft_deletion(): void
    {
        $creator = User::factory()->create()->assignRole('requester');
        $other = User::factory()->create();
        $this->actingAs($creator);
        $category = ServiceCategory::factory()->create();
        $request = ServiceRequest::factory()->make(['service_category_id' => $category->id]);
        $request->created_by = $other->id;
        $request->save();

        $this->assertSame($creator->id, $request->creator->id);
        $this->assertSame($category->id, $request->category->id);
        $this->assertSame(ServiceRequestStatus::Submitted, $request->status);
        $this->assertStringStartsWith('SR-', $request->request_no);

        $this->actingAs($other);
        $category->update(['description' => 'Updated']);
        $this->assertSame($other->id, $category->updater->id);
        $category->delete();
        $this->assertNull(ServiceCategory::find($category->id));
        $this->assertTrue($request->fresh()->category->trashed());
        $this->actingAs($creator);
        $request->delete();
        $this->assertNull(ServiceRequest::find($request->id));
        $this->assertNotNull(ServiceRequest::withTrashed()->find($request->id));
    }

    public function test_creator_cannot_be_reassigned(): void
    {
        $this->actingAs(User::factory()->create()->assignRole('requester'));
        $request = ServiceRequest::factory()->create();
        $request->created_by = User::factory()->create()->id;
        $this->expectException(ValidationException::class);
        $request->save();
    }

    public function test_completion_cannot_be_saved_without_assignment_and_note(): void
    {
        $this->actingAs(User::factory()->create()->assignRole('requester'));
        $request = ServiceRequest::factory()->create();
        $request->status = ServiceRequestStatus::Completed;
        $request->completion_note = '   ';
        $this->expectException(ValidationException::class);
        $request->save();
    }
}
