<?php

namespace Tests\Feature\Facilities;

use App\Models\ServiceCategory;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Services\ServiceRequestWorkflow;
use Database\Seeders\FacilitiesRoleSeeder;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Role;

class AuthorizationTest extends FacilitiesTestCase
{
    public function test_visibility_and_permissions_for_each_role(): void
    {
        $this->seed(FacilitiesRoleSeeder::class);
        $requester = User::factory()->create()->assignRole('requester');
        $other = User::factory()->create()->assignRole('requester');
        $staff = User::factory()->create()->assignRole('service_staff');
        $supervisor = User::factory()->create()->assignRole('service_supervisor');
        Role::findOrCreate('panel_user', 'web');
        $unprivileged = User::factory()->create()->assignRole('panel_user');
        $this->actingAs($requester);
        $request = ServiceRequest::factory()->create();

        $this->assertTrue(Gate::forUser($requester)->allows('view', $request));
        $this->assertFalse(Gate::forUser($other)->allows('view', $request));
        $this->assertFalse(Gate::forUser($staff)->allows('view', $request));
        $this->assertTrue(Gate::forUser($supervisor)->allows('assign', $request));
        $this->assertFalse(Gate::forUser($requester)->allows('assign', $request));
        $this->assertSame(0, ServiceRequest::visibleTo($unprivileged)->count());
        $this->assertSame(0, ServiceRequest::visibleTo(null)->count());
        $this->assertSame(1, ServiceRequest::visibleTo($supervisor)->count());

        $this->actingAs($supervisor);
        $request = app(ServiceRequestWorkflow::class)->assign($request, $staff->id);
        $this->assertTrue(Gate::forUser($staff)->allows('start', $request));
        $this->assertFalse(Gate::forUser($requester)->allows('update', $request));
        $this->assertFalse(Gate::forUser($other)->allows('start', $request));
        $this->assertSame(1, ServiceRequest::visibleTo($staff)->count());
    }

    public function test_category_management_is_reserved_for_supervisors_and_admins(): void
    {
        $this->seed(FacilitiesRoleSeeder::class);
        foreach (['requester' => false, 'service_staff' => false, 'service_supervisor' => true, 'super_admin' => true] as $role => $allowed) {
            $user = User::factory()->create()->assignRole($role);
            $this->assertSame($allowed, Gate::forUser($user)->allows('create', ServiceCategory::class));
        }
    }
}
