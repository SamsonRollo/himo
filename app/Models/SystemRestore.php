<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class SystemRestore extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw ValidationException::withMessages(['restore' => 'Restore audit records cannot be edited.']));
        static::deleting(fn () => throw ValidationException::withMessages(['restore' => 'Restore audit records cannot be deleted.']));
    }

    public function backup(): BelongsTo
    {
        return $this->belongsTo(SystemBackup::class, 'system_backup_id');
    }

    public function restoredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'restored_by');
    }
}
