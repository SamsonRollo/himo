<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Validation\ValidationException;

/**
 * Generic, append-only audit trail for changes that don't have a dedicated
 * history table of their own (schedule reassignment, staff status). See
 * ServiceRequestStatusHistory for the request-specific equivalent this
 * mirrors.
 */
class ActivityLog extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw ValidationException::withMessages(['activity_log' => 'Activity log entries cannot be edited.']));
        static::deleting(fn () => throw ValidationException::withMessages(['activity_log' => 'Activity log entries cannot be deleted.']));
    }

    /**
     * Record one append-only entry. Callers pass already-stringified
     * from/to values (e.g. an ISO datetime or an enum value) since this log
     * has no knowledge of the subject's own value types.
     */
    public static function record(Model $subject, string $action, ?string $from, ?string $to, ?string $remarks = null): self
    {
        $log = new self;
        $log->forceFill([
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'action' => $action,
            'from_value' => $from,
            'to_value' => $to,
            'remarks' => $remarks,
            'changed_by' => auth()->id(),
        ])->save();

        return $log;
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
