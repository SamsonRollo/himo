<?php

namespace Database\Seeders;

use App\Models\ServiceCategory;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Services\ServiceRequestWorkflow;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class FacilitiesDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('Facilities demonstration accounts are only for non-production environments.');
        }

        $this->call(FacilitiesRoleSeeder::class);
        $previousUser = Auth::user();

        try {
            DB::transaction(function (): void {
                $actors = [];
                foreach (['requester' => 'Requester', 'service_staff' => 'Service Staff', 'service_supervisor' => 'Service Supervisor'] as $role => $name) {
                    $user = User::firstOrCreate(['email' => 'facilities-'.$role.'@example.test'], [
                        'name' => 'Demo '.$name,
                        'password' => 'facilities-demo-only',
                        'email_verified_at' => now(),
                    ]);
                    if ($user->wasRecentlyCreated) {
                        // Phase 8: every user also holds "requester" by default.
                        $user->assignRole(array_unique([$role, 'requester']));
                    } elseif (! $user->hasRole($role)) {
                        throw new RuntimeException('A facilities demo email is already in use with a different role; no account was overwritten.');
                    }
                    $actors[$role] = $user;
                }

                Auth::setUser($actors['service_supervisor']);
                $electrical = ServiceCategory::firstOrCreate(['name' => 'Demo Electrical'], ['description' => 'Demonstration electrical services', 'is_active' => true]);
                $plumbing = ServiceCategory::firstOrCreate(['name' => 'Demo Plumbing'], ['description' => 'Demonstration plumbing services', 'is_active' => true]);
                $workflow = app(ServiceRequestWorkflow::class);

                foreach (['submitted', 'assigned', 'in_progress', 'for_confirmation', 'completed', 'correction'] as $index => $stage) {
                    $description = 'Facilities demo: '.$stage;
                    if (ServiceRequest::withTrashed()->where('created_by', $actors['requester']->id)->where('description', $description)->exists()) {
                        continue;
                    }
                    Auth::setUser($actors['requester']);
                    // Staggered per index: all six requests share the same
                    // lone demo Service Staff member, so identical windows
                    // would collide with the assignment conflict check.
                    $neededStart = now()->addDay()->addHours($index * 3);
                    $request = $workflow->submit([
                        'service_category_id' => $index % 2 === 0 ? $electrical->id : $plumbing->id,
                        'location' => 'Demo room '.($index + 1),
                        'description' => $description,
                        'needed_start_at' => $neededStart,
                        'needed_end_at' => $neededStart->copy()->addHours(2),
                    ]);
                    if ($stage === 'submitted') {
                        continue;
                    }
                    Auth::setUser($actors['service_supervisor']);
                    $request = $workflow->assign($request, $actors['service_staff']->id);
                    if ($stage === 'assigned') {
                        continue;
                    }
                    Auth::setUser($actors['service_staff']);
                    $request = $workflow->start($request);
                    if ($stage === 'in_progress') {
                        continue;
                    }
                    $request = $workflow->submitForConfirmation($request, 'Repair completed and function tested.');
                    Auth::setUser($actors['requester']);
                    if ($stage === 'completed') {
                        $workflow->complete($request);
                    } elseif ($stage === 'correction') {
                        $workflow->returnForCorrection($request, 'Please recheck the repair during normal operation.');
                    }
                }
            });
        } finally {
            if ($previousUser) {
                Auth::setUser($previousUser);
            } else {
                Auth::forgetUser();
            }
        }
    }
}
