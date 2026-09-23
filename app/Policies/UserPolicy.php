<?php

namespace App\Policies;

use App\Enums\UserStatus;
use App\Models\User;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canListUsers($user);
    }

    public function view(User $user, User $subject): bool
    {
        return $this->canListUsers($user);
    }

    public function create(User $user): bool
    {
        return $this->isSuperAdmin($user);
    }

    public function update(User $user, User $subject): bool
    {
        return $this->isSuperAdmin($user);
    }

    /**
     * Role-based rather than permission-based: roles are read from the
     * database, so a stale Spatie permission cache cannot lock a Super
     * Admin out of user management or open it to other roles.
     */
    private function canListUsers(User $user): bool
    {
        return $user->hasRole(['service_supervisor', config('filament-shield.super_admin.name')]);
    }

    private function isSuperAdmin(User $user): bool
    {
        return $user->hasRole(config('filament-shield.super_admin.name'));
    }

    /**
     * Governs the deactivate/reactivate toggle, never a real Eloquent
     * delete. A Super Admin may not deactivate their own account (avoids
     * an accidental self-lockout) or the last remaining active Super Admin.
     */
    public function delete(User $user, User $subject): bool
    {
        if (! $this->isSuperAdmin($user)) {
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
