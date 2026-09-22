<?php

namespace App\Policies;

use App\Enums\ServiceRequestStatus;
use App\Models\ServiceRequest;
use App\Models\User;

class ServiceRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('ViewAny:ServiceRequest');
    }

    public function view(User $user, ServiceRequest $request): bool
    {
        return ! $request->trashed() && $user->can('View:ServiceRequest')
            && ServiceRequest::query()->visibleTo($user)->whereKey($request->id)->exists();
    }

    public function create(User $user): bool
    {
        return $user->can('Create:ServiceRequest') && $user->hasRole(['requester', config('filament-shield.super_admin.name')]);
    }

    public function update(User $user, ServiceRequest $request): bool
    {
        return $this->view($user, $request) && $user->can('Update:ServiceRequest')
            && $request->status === ServiceRequestStatus::Submitted
            && ($request->created_by === $user->id || $this->isAdmin($user));
    }

    public function delete(User $user, ServiceRequest $request): bool
    {
        return $this->update($user, $request) && $user->can('Delete:ServiceRequest');
    }

    public function assign(User $user, ServiceRequest $request): bool
    {
        return $this->view($user, $request) && $user->can('Assign:ServiceRequest')
            && $user->hasRole(['service_supervisor', config('filament-shield.super_admin.name')])
            && $request->status === ServiceRequestStatus::Submitted;
    }

    public function start(User $user, ServiceRequest $request): bool
    {
        return $this->canWork($user, $request) && $request->status === ServiceRequestStatus::Assigned;
    }

    public function updateWork(User $user, ServiceRequest $request): bool
    {
        return $this->canWork($user, $request) && $request->status === ServiceRequestStatus::InProgress;
    }

    public function submitForConfirmation(User $user, ServiceRequest $request): bool
    {
        return $this->updateWork($user, $request);
    }

    public function complete(User $user, ServiceRequest $request): bool
    {
        return $this->canConfirm($user, $request) && $user->can('Complete:ServiceRequest');
    }

    public function returnForCorrection(User $user, ServiceRequest $request): bool
    {
        return $this->canConfirm($user, $request) && $user->can('Return:ServiceRequest');
    }

    private function canConfirm(User $user, ServiceRequest $request): bool
    {
        return $this->view($user, $request) && $request->status === ServiceRequestStatus::ForConfirmation
            && ($user->hasRole('service_supervisor') || $this->isAdmin($user)
                || ($user->hasRole('requester') && $request->created_by === $user->id));
    }

    private function canWork(User $user, ServiceRequest $request): bool
    {
        return $this->view($user, $request) && $user->can('Work:ServiceRequest')
            && (($user->hasRole('service_staff') && $request->assigned_to === $user->id) || $this->isAdmin($user));
    }

    private function isAdmin(User $user): bool
    {
        return $user->hasRole(config('filament-shield.super_admin.name'));
    }
}
