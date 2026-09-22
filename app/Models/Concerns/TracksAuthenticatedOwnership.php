<?php

namespace App\Models\Concerns;

use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

trait TracksAuthenticatedOwnership
{
    public static function bootTracksAuthenticatedOwnership(): void
    {
        static::creating(function ($record): void {
            $record->created_by = auth()->id() ?? throw new AuthenticationException;
            $record->updated_by = null;
        });

        static::updating(function ($record): void {
            if ($record->isDirty('created_by')) {
                throw ValidationException::withMessages(['created_by' => 'The creator cannot be changed.']);
            }

            $record->updated_by = auth()->id() ?? throw new AuthenticationException;
        });

        static::deleting(function ($record): void {
            if ($record->isForceDeleting()) {
                throw ValidationException::withMessages(['record' => 'Permanent deletion is not supported.']);
            }

            $record->updated_by = auth()->id() ?? throw new AuthenticationException;
            $record->save();
        });
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
