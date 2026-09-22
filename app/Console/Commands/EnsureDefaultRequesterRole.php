<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Phase 8 backfill: every existing user gets the "requester" role, keeping
 * whatever other roles they already hold. Idempotent — a user who already
 * has it is skipped, so reruns never create duplicate pivot rows and never
 * touch anything else about the account (password, name, other roles).
 */
#[Signature('app:ensure-default-requester-role')]
#[Description('Give every existing user the default "requester" role, without disturbing their other roles or credentials.')]
class EnsureDefaultRequesterRole extends Command
{
    public function handle(): int
    {
        $guard = config('auth.defaults.guard', 'web');
        $role = Role::findOrCreate('requester', $guard);

        $missing = User::query()
            ->whereDoesntHave('roles', fn ($query) => $query->where('roles.id', $role->id))
            ->get(['id', 'name']);

        if ($missing->isEmpty()) {
            $this->info('Every user already has the requester role. Nothing to do.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($missing, $role): void {
            foreach ($missing as $user) {
                $user->roles()->attach($role->id);
            }
        });
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->info(sprintf('Assigned the requester role to %d user(s): %s', $missing->count(), $missing->pluck('name')->implode(', ')));

        return self::SUCCESS;
    }
}
