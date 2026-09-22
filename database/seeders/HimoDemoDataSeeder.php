<?php

namespace Database\Seeders;

use App\Enums\ServiceRequestStatus as Status;
use App\Models\ServiceCategory;
use App\Models\User;
use App\Services\ServiceRequestWorkflow;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * Realistic-scale demonstration dataset for the HIMO facilities panel:
 * 5 service categories, 5 service supervisors, 20 service staff (4 per
 * category), 100 requesters, 1 Super Admin, and 700 service requests spread
 * across the full status workflow.
 *
 * Every request is produced by driving the real ServiceRequestWorkflow
 * (never inserted directly), so the completion business rule, status
 * history, and assignment log all come out exactly as they would from the
 * UI. `Carbon::setTestNow()` walks fake "now" forward through each request's
 * lifecycle so created/assigned/completed timestamps stay chronologically
 * consistent without touching production time.
 *
 * Idempotent at the account level (guarded by a fixed Super Admin email) so
 * rerunning against a non-empty database is a no-op rather than a
 * duplicate-generating retry.
 */
class HimoDemoDataSeeder extends Seeder
{
    private const SUPER_ADMIN_EMAIL = 'super.admin@himo.test';

    private const REQUESTS_PER_CATEGORY = 140;

    /**
     * Tracks each staff member's next free slot so generated schedules for
     * assigned+ requests never collide with ServiceRequestWorkflow::assign()'s
     * own overlap check. Keyed by staff id, value is the end of their last
     * scheduled window (plus a buffer).
     *
     * @var array<int, Carbon>
     */
    private array $staffScheduleCursor = [];

    /** @var array<string, array{issues: list<string>, locations: list<string>, notes: list<string>}> */
    private const CATEGORY_DATA = [
        'Electrical' => [
            'issues' => [
                'Flickering lights in the room',
                'Power outlet not working',
                'Circuit breaker keeps tripping',
                'Exposed wiring near the ceiling',
                'Air-conditioning unit has no power',
                'Corridor lights are out',
            ],
            'locations' => ['Room 204, Melchor Hall', 'Room 3B, Engineering Complex', 'Faculty Lounge, AS Building', 'Room 112, College of Science', '2nd Floor Corridor, Quezon Hall'],
            'notes' => [
                'Replaced faulty circuit breaker and tested the load.',
                'Rewired the affected outlet and confirmed power is stable.',
                'Replaced the ballast and tested the lighting fixture.',
                'Secured exposed wiring and applied proper insulation.',
            ],
        ],
        'Plumbing' => [
            'issues' => [
                'Leaking faucet in the restroom',
                'Clogged drain in the pantry',
                'Toilet not flushing properly',
                'Low water pressure on the floor',
                'Water leak from the ceiling pipe',
                'Broken sink handle',
            ],
            'locations' => ['Restroom 2F, University Library', 'Pantry, College of Business', 'Restroom, Student Union Building', 'Ground Floor, Palma Hall', 'Faculty Restroom, NIP Building'],
            'notes' => [
                'Replaced the worn washer and sealed the fitting.',
                'Cleared the blockage and flushed the line.',
                'Replaced the flush valve and tested the mechanism.',
                'Repaired the pipe joint and confirmed no further leaks.',
            ],
        ],
        'IT & Networking' => [
            'issues' => [
                'No internet connection in the room',
                'Projector not detecting laptop input',
                'Wi-Fi access point offline',
                'Network switch port not working',
                'Computer unable to log in to the domain',
                'Printer not connecting to the network',
            ],
            'locations' => ['Computer Lab 1, College of Science', 'Room 301, Melchor Hall', 'Faculty Office, Engineering Complex', 'AVR, Student Union Building', 'Registrar Office, Quezon Hall'],
            'notes' => [
                'Reset the access point and confirmed connectivity.',
                'Replaced the patch cable and verified the port link.',
                'Reconfigured the display output and tested the projector.',
                'Restarted network services and confirmed domain login.',
            ],
        ],
        'Building & Carpentry' => [
            'issues' => [
                'Broken door hinge',
                'Damaged ceiling tile',
                'Loose window latch',
                'Cracked classroom chair',
                'Jammed office door lock',
                'Damaged whiteboard mounting',
            ],
            'locations' => ['Room 108, Palma Hall', 'Room 5A, College of Business', 'Faculty Room, AS Building', 'Room 210, Engineering Complex', 'Lobby, University Library'],
            'notes' => [
                'Replaced the hinge and confirmed the door closes properly.',
                'Installed a new ceiling tile matching the existing set.',
                'Repaired the latch mechanism and tested it.',
                'Reinforced the mounting and confirmed it is secure.',
            ],
        ],
        'Grounds & HVAC' => [
            'issues' => [
                'Air-conditioning unit not cooling',
                'Overgrown grass along the walkway',
                'Clogged gutter causing water overflow',
                'HVAC making unusual noise',
                'Fallen tree branch blocking the path',
                'Ventilation fan not spinning',
            ],
            'locations' => ['Room 2C, College of Science', 'Walkway near Quezon Hall', 'Rear Grounds, Engineering Complex', 'Faculty Room, Melchor Hall', 'Courtyard, Student Union Building'],
            'notes' => [
                'Recharged the refrigerant and confirmed proper cooling.',
                'Cleared the gutter and tested water flow.',
                'Trimmed the grass and cleared the walkway.',
                'Replaced the fan motor and confirmed normal operation.',
            ],
        ],
    ];

    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('HIMO demonstration data is only for non-production environments.');
        }

        $this->call(FacilitiesRoleSeeder::class);

        if (User::where('email', self::SUPER_ADMIN_EMAIL)->exists()) {
            $this->command?->info('HIMO demo data already present; skipping.');

            return;
        }

        mt_srand(20260922);
        fake()->seed(20260922);

        $previousUser = Auth::user();

        try {
            [$superAdmin, $supervisors, $staffByCategory, $requesters] = DB::transaction(fn () => $this->seedAccounts());

            $categories = DB::transaction(fn () => $this->seedCategories($superAdmin));

            foreach (array_keys(self::CATEGORY_DATA) as $categoryName) {
                $this->seedRequestsForCategory(
                    $categories[$categoryName],
                    $categoryName,
                    $supervisors[$categoryName],
                    $staffByCategory[$categoryName],
                    $requesters,
                );
            }
        } finally {
            Carbon::setTestNow();
            $previousUser ? Auth::setUser($previousUser) : Auth::forgetUser();
        }

        $this->printAccounts($superAdmin, $supervisors, $staffByCategory, $requesters);
    }

    /**
     * @return array{0: User, 1: array<string, User>, 2: array<string, list<User>>, 3: list<User>}
     */
    private function seedAccounts(): array
    {
        $superAdmin = User::updateOrCreate(
            ['email' => self::SUPER_ADMIN_EMAIL],
            ['name' => 'HIMO Super Admin', 'password' => Hash::make('password'), 'email_verified_at' => now()],
        );
        // Phase 8: every user also holds "requester" by default.
        $superAdmin->syncRoles([config('filament-shield.super_admin.name', 'super_admin'), 'requester']);

        $categoryNames = array_keys(self::CATEGORY_DATA);
        $supervisors = [];
        foreach ($categoryNames as $index => $categoryName) {
            $n = $index + 1;
            $user = User::updateOrCreate(
                ['email' => "supervisor{$n}@himo.test"],
                ['name' => 'Supervisor '.chr(65 + $index).' ('.$categoryName.')', 'password' => Hash::make('password'), 'email_verified_at' => now()],
            );
            $user->syncRoles(['service_supervisor', 'requester']);
            $supervisors[$categoryName] = $user;
        }

        $staffByCategory = [];
        $staffCounter = 1;
        foreach ($categoryNames as $categoryName) {
            $staffByCategory[$categoryName] = [];
            for ($i = 0; $i < 4; $i++) {
                $user = User::updateOrCreate(
                    ['email' => "staff{$staffCounter}@himo.test"],
                    ['name' => fake()->name(), 'password' => Hash::make('password'), 'email_verified_at' => now()],
                );
                $user->syncRoles(['service_staff', 'requester']);
                $staffByCategory[$categoryName][] = $user;
                $staffCounter++;
            }
        }

        $requesters = [];
        for ($i = 1; $i <= 100; $i++) {
            $user = User::updateOrCreate(
                ['email' => "requester{$i}@himo.test"],
                ['name' => fake()->name(), 'password' => Hash::make('password'), 'email_verified_at' => now()],
            );
            $user->syncRoles(['requester']);
            $requesters[] = $user;
        }

        return [$superAdmin, $supervisors, $staffByCategory, $requesters];
    }

    /**
     * @return array<string, ServiceCategory>
     */
    private function seedCategories(User $superAdmin): array
    {
        Auth::setUser($superAdmin);

        $categories = [];
        foreach (self::CATEGORY_DATA as $name => $data) {
            $categories[$name] = ServiceCategory::firstOrCreate(
                ['name' => $name],
                ['description' => $name.' service requests for campus facilities.', 'is_active' => true],
            );
        }

        return $categories;
    }

    /**
     * @param  list<User>  $staff
     * @param  list<User>  $requesters
     */
    private function seedRequestsForCategory(ServiceCategory $category, string $categoryName, User $supervisor, array $staff, array $requesters): void
    {
        $data = self::CATEGORY_DATA[$categoryName];
        $workflow = app(ServiceRequestWorkflow::class);

        // 21 submitted / 21 assigned / 28 in_progress / 14 for_confirmation / 56 completed = 140.
        $stagePlan = [
            ...array_fill(0, 21, Status::Submitted),
            ...array_fill(0, 21, Status::Assigned),
            ...array_fill(0, 28, Status::InProgress),
            ...array_fill(0, 14, Status::ForConfirmation),
            ...array_fill(0, 56, Status::Completed),
        ];
        shuffle($stagePlan);

        foreach ($stagePlan as $index => $targetStage) {
            $requester = $requesters[array_rand($requesters)];
            $assignedStaff = $staff[array_rand($staff)];
            $issue = fake()->randomElement($data['issues']);
            $location = fake()->randomElement($data['locations']);
            $note = fake()->randomElement($data['notes']);

            // Completed requests are backdated further into the demo window;
            // still-open ones skew recent, so the dataset reads as an
            // in-progress operation rather than a frozen snapshot.
            $daysAgo = match ($targetStage) {
                Status::Submitted => fake()->numberBetween(0, 5),
                Status::Assigned => fake()->numberBetween(3, 10),
                Status::InProgress => fake()->numberBetween(5, 20),
                Status::ForConfirmation => fake()->numberBetween(10, 30),
                Status::Completed => fake()->numberBetween(15, 150),
            };
            $submittedAt = now()->subDays($daysAgo)->setTime(fake()->numberBetween(7, 17), fake()->numberBetween(0, 59));

            $neededStart = $submittedAt->copy()->addDays(fake()->numberBetween(1, 3))->setTime(fake()->numberBetween(7, 16), 0);
            $neededEnd = $neededStart->copy()->addHours(2);

            // Requests that stay Submitted never reach assign(), so they
            // carry no staff-conflict risk; only reserve a cursor slot for
            // stages that will actually be assigned to $assignedStaff.
            if ($targetStage !== Status::Submitted) {
                $cursor = $this->staffScheduleCursor[$assignedStaff->id] ?? null;
                if ($cursor && $neededStart->lt($cursor)) {
                    $neededStart = $cursor->copy();
                    $neededEnd = $neededStart->copy()->addHours(2);
                }
                $this->staffScheduleCursor[$assignedStaff->id] = $neededEnd->copy()->addMinutes(30);
            }

            Carbon::setTestNow($submittedAt);
            Auth::setUser($requester);
            $request = $workflow->submit([
                'service_category_id' => $category->id,
                'location' => $location,
                'description' => $issue.' at '.$location.'.',
                'needed_start_at' => $neededStart,
                'needed_end_at' => $neededEnd,
            ]);

            if ($targetStage === Status::Submitted) {
                continue;
            }

            $assignedAt = $submittedAt->copy()->addDays(fake()->numberBetween(1, 3));
            Carbon::setTestNow($assignedAt);
            Auth::setUser($supervisor);
            $request = $workflow->assign($request, $assignedStaff->id);

            if ($targetStage === Status::Assigned) {
                continue;
            }

            $startedAt = $assignedAt->copy()->addDays(fake()->numberBetween(1, 2));
            Carbon::setTestNow($startedAt);
            Auth::setUser($assignedStaff);
            $request = $workflow->start($request);

            if ($targetStage === Status::InProgress) {
                continue;
            }

            $confirmAt = $startedAt->copy()->addDays(fake()->numberBetween(1, 4));
            Carbon::setTestNow($confirmAt);
            Auth::setUser($assignedStaff);
            $request = $workflow->submitForConfirmation($request, $note);

            // Roughly one in six goes through a single correction round trip
            // before final completion, demonstrating the workflow's
            // alternate path in the seeded data.
            if ($targetStage === Status::Completed && $index % 6 === 0) {
                $returnAt = $confirmAt->copy()->addHours(fake()->numberBetween(2, 20));
                Carbon::setTestNow($returnAt);
                Auth::setUser($supervisor);
                $request = $workflow->returnForCorrection($request, 'Please recheck and confirm the repair holds under normal use.');

                $reworkAt = $returnAt->copy()->addDays(fake()->numberBetween(1, 2));
                Carbon::setTestNow($reworkAt);
                Auth::setUser($assignedStaff);
                $request = $workflow->submitForConfirmation($request, $note.' Reverified after correction.');
                $confirmAt = $reworkAt;
            }

            if ($targetStage === Status::ForConfirmation) {
                continue;
            }

            $completedAt = $confirmAt->copy()->addDays(fake()->numberBetween(0, 2));
            Carbon::setTestNow($completedAt);
            Auth::setUser($index % 2 === 0 ? $requester : $supervisor);
            $workflow->complete($request);
        }
    }

    /**
     * @param  array<string, User>  $supervisors
     * @param  array<string, list<User>>  $staffByCategory
     * @param  list<User>  $requesters
     */
    private function printAccounts(User $superAdmin, array $supervisors, array $staffByCategory, array $requesters): void
    {
        if (! $this->command) {
            return;
        }

        $this->command->info('HIMO demo accounts seeded (development only, password: "password"):');
        $this->command->line('  Super Admin: '.$superAdmin->email);
        foreach ($supervisors as $categoryName => $supervisor) {
            $this->command->line("  Supervisor ({$categoryName}): {$supervisor->email}");
        }
        $this->command->line('  Service Staff: staff1@himo.test .. staff20@himo.test');
        $this->command->line('  Requesters: requester1@himo.test .. requester100@himo.test');
    }
}
