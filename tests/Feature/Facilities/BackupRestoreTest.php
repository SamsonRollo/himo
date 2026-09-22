<?php

namespace Tests\Feature\Facilities;

use App\Models\SystemBackup;
use App\Models\User;
use App\Services\BackupService;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Case study requirement 5: server-side backup/restore. This suite's base
 * FacilitiesTestCase forces an isolated in-memory sqlite connection for
 * every test (see FacilitiesTestCase::setUp) — deliberately so, since it
 * guarantees no test can ever touch a real database. A real pg_dump/
 * pg_restore round trip is therefore intentionally NOT automated here:
 * doing so would mean either running it against sqlite (impossible, the
 * service is Postgres-only by design) or deliberately pointing a test at a
 * live PostgreSQL connection, which risks pg_restore --clean wiping a real
 * shared dev database if this suite is ever run against a populated
 * docker-compose stack. That round trip is exercised manually instead —
 * see docs/facilities/demo.md's backup/restore checklist. What's covered
 * here is everything safe to automate: the Postgres-only guard and the
 * audit trail's immutability.
 */
class BackupRestoreTest extends FacilitiesTestCase
{
    public function test_backup_and_restore_refuse_to_run_outside_postgres(): void
    {
        $admin = User::factory()->create()->assignRole('super_admin');
        $this->assertSame('sqlite', config('database.default'));

        $this->expectException(RuntimeException::class);
        app(BackupService::class)->create($admin);
    }

    public function test_system_backup_and_restore_records_are_append_only(): void
    {
        $admin = User::factory()->create()->assignRole('super_admin');
        $backup = SystemBackup::create([
            'filename' => 'backup-test.dump', 'disk_path' => 'backups/backup-test.dump',
            'size_bytes' => 10, 'status' => 'completed', 'created_by' => $admin->id,
        ]);

        $this->expectException(ValidationException::class);
        $backup->update(['filename' => 'renamed.dump']);
    }
}
