<?php

namespace App\Services;

use App\Models\SystemBackup;
use App\Models\SystemRestore;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Shells out to pg_dump/pg_restore (already installed in the PHP container
 * for pg_isready — see .docker/php/Dockerfile) to take and restore full,
 * server-side database backups. Postgres-only: this stack has no other
 * database connection configured, so no generic multi-driver branch exists.
 *
 * Backups are stored under the private `local` disk (storage/app/private),
 * which is never web-served — see config/filesystems.php.
 */
class BackupService
{
    private const DISK = 'local';

    private const DIRECTORY = 'backups';

    public function create(User $actor): SystemBackup
    {
        $this->assertPostgres();

        $connection = $this->connectionConfig();
        $filename = 'backup-'.now()->format('Ymd_His').'-'.Str::lower((string) Str::ulid()).'.dump';
        $relativePath = self::DIRECTORY.'/'.$filename;

        Storage::disk(self::DISK)->makeDirectory(self::DIRECTORY);
        $absolutePath = Storage::disk(self::DISK)->path($relativePath);

        $process = new Process([
            'pg_dump',
            '--format=custom',
            '--no-owner',
            '--no-privileges',
            '--file='.$absolutePath,
            '--host='.$connection['host'],
            '--port='.$connection['port'],
            '--username='.$connection['username'],
            $connection['database'],
        ]);
        $process->setTimeout(600);
        $process->setEnv(['PGPASSWORD' => $connection['password']]);
        $process->run();

        if (! $process->isSuccessful() || ! is_file($absolutePath)) {
            $message = Str::limit(trim($process->getErrorOutput()) ?: 'pg_dump did not produce an output file.', 2000);
            SystemBackup::create([
                'filename' => $filename,
                'disk_path' => $relativePath,
                'size_bytes' => 0,
                'status' => 'failed',
                'error_message' => $message,
                'created_by' => $actor->id,
            ]);
            if (is_file($absolutePath)) {
                @unlink($absolutePath);
            }

            throw new RuntimeException('Backup failed: '.$message);
        }

        return SystemBackup::create([
            'filename' => $filename,
            'disk_path' => $relativePath,
            'size_bytes' => filesize($absolutePath),
            'checksum_sha256' => hash_file('sha256', $absolutePath),
            'status' => 'completed',
            'created_by' => $actor->id,
        ]);
    }

    /**
     * @throws RuntimeException on any failure; a SystemRestore audit row is
     *                          always written first so the attempt is never lost.
     */
    public function restore(SystemBackup $backup, User $actor): SystemRestore
    {
        $this->assertPostgres();

        $absolutePath = Storage::disk(self::DISK)->path($backup->disk_path);

        if (! is_file($absolutePath)) {
            $this->failRestore($backup, $actor, 'Backup file is missing from server storage.');
        }

        if ($backup->checksum_sha256 && hash_file('sha256', $absolutePath) !== $backup->checksum_sha256) {
            $this->failRestore($backup, $actor, 'Backup file checksum no longer matches the recorded value; refusing to restore a possibly corrupted file.');
        }

        $connection = $this->connectionConfig();

        // Block concurrent writes for the duration of the restore.
        Artisan::call('down', ['--retry' => 15]);

        try {
            $process = new Process([
                'pg_restore',
                '--clean',
                '--if-exists',
                '--no-owner',
                '--no-privileges',
                '--single-transaction',
                '--host='.$connection['host'],
                '--port='.$connection['port'],
                '--username='.$connection['username'],
                '--dbname='.$connection['database'],
                $absolutePath,
            ]);
            $process->setTimeout(900);
            $process->setEnv(['PGPASSWORD' => $connection['password']]);
            $process->run();

            if (! $process->isSuccessful()) {
                $this->failRestore($backup, $actor, Str::limit(trim($process->getErrorOutput()) ?: 'pg_restore failed.', 2000));
            }

            return SystemRestore::create([
                'system_backup_id' => $backup->id,
                'status' => 'completed',
                'restored_by' => $actor->id,
            ]);
        } finally {
            Artisan::call('up');
        }
    }

    public function delete(SystemBackup $backup): void
    {
        Storage::disk(self::DISK)->delete($backup->disk_path);
        $backup->delete();
    }

    public function downloadPath(SystemBackup $backup): string
    {
        return Storage::disk(self::DISK)->path($backup->disk_path);
    }

    /**
     * @return never
     *
     * @throws RuntimeException
     */
    private function failRestore(SystemBackup $backup, User $actor, string $message): void
    {
        SystemRestore::create([
            'system_backup_id' => $backup->id,
            'status' => 'failed',
            'error_message' => $message,
            'restored_by' => $actor->id,
        ]);

        throw new RuntimeException('Restore failed: '.$message);
    }

    private function assertPostgres(): void
    {
        if (config('database.default') !== 'pgsql') {
            throw new RuntimeException('Server-side backup and restore is only implemented for this application\'s PostgreSQL connection.');
        }
    }

    /**
     * @return array{host: string, port: string, database: string, username: string, password: string}
     */
    private function connectionConfig(): array
    {
        $connection = config('database.connections.pgsql');

        return [
            'host' => (string) $connection['host'],
            'port' => (string) $connection['port'],
            'database' => (string) $connection['database'],
            'username' => (string) $connection['username'],
            'password' => (string) $connection['password'],
        ];
    }
}
