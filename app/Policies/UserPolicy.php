<?php

namespace App\Policies;

use App\Enums\UserStatus;
use App\Models\User;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('ViewAny:User');
    }

    public function view(User $user, User $subject): bool
    {
        return $user->can('View:User');
    }

    public function create(User $user): bool
    {
        return $user->can('Create:User');
    }

    public function update(User $user, User $subject): bool
    {
        return $user->can('Update:User');
    }

    /**
     * Governs the deactivate/reactivate toggle, never a real Eloquent
     * delete. A Super Admin may not deactivate their own account (avoids
     * an accidental self-lockout) or the last remaining active Super Admin.
     */
    public function delete(User $user, User $subject): bool
    {
        if (! $user->can('Delete:User')) {
            return false;
        }

        if ($subject->is($user)) {
            return false;
        }

        if ($subject->status === UserStatus::Active && $subject->hasRole(config('filament-shield.super_admin.name'))) {
            $remainingActiveAdmins = User::role(config('filament-shield.super_admin.name'))
                ->active()
                ->whereKeyNot($subject->id)
                ->exists();

            if (! $remainingActiveAdmins) {
                return false;
            }
        }

        return true;
    }
}
