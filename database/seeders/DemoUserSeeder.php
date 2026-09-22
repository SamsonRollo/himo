<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * One ready-to-use login per role, so a fresh `docker compose up` lands on a
 * panel you can actually sign into. Idempotent: re-seeding updates the same
 * accounts instead of duplicating them.
 */
class DemoUserSeeder extends Seeder
{
    public function run(): void
    {
        $password = env('DEMO_USER_PASSWORD', 'password');

        $accounts = [
            ['admin@example.com', 'Super Admin', config('filament-shield.super_admin.name', 'super_admin')],
            ['requester@example.com', 'Requesting Unit', 'requester'],
            ['custodian@example.com', 'Warehouse Staff', 'custodian'],
            ['approver@example.com', 'Supply Officer', 'approver'],
        ];

        foreach ($accounts as [$email, $name, $role]) {
            $user = User::updateOrCreate(
                ['email' => $email],
                [
                    'name' => $name,
                    'password' => Hash::make($password),
                    'email_verified_at' => now(),
                ],
            );

            $user->syncRoles([$role]);
        }
    }
}
