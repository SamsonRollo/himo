<?php

namespace App\Policies;

use App\Models\ServiceCategory;
use App\Models\User;

class ServiceCategoryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('ViewAny:ServiceCategory');
    }

    public function view(User $user, ServiceCategory $category): bool
    {
        return ! $category->trashed() && $user->can('View:ServiceCategory');
    }

    public function create(User $user): bool
    {
        return $user->can('Create:ServiceCategory') && $user->hasRole(['service_supervisor', config('filament-shield.super_admin.name')]);
    }

    public function update(User $user, ServiceCategory $category): bool
    {
        return ! $category->trashed() && $this->create($user) && $user->can('Update:ServiceCategory');
    }

    public function delete(User $user, ServiceCategory $category): bool
    {
        return ! $category->trashed() && $this->create($user) && $user->can('Delete:ServiceCategory');
    }
}
