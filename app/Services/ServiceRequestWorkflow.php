<?php

namespace App\Services;

use App\Enums\ServiceRequestStatus as Status;
use App\Enums\UserStatus;
use App\Models\ActivityLog;
use App\Models\ServiceCategory;
use App\Models\ServiceRequest;
use App\Models\ServiceRequestAssignment;
use App\Models\User;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use WeakMap;

class ServiceRequestWorkflow
{
    private static ?WeakMap $writes = null;

    public static function isWriting(ServiceRequest $request): bool
    {
        return isset(self::$writes[$request]);
    }

    public function submit(array $data): ServiceRequest
    {
        Gate::authorize('create', ServiceRequest::class);

        return ServiceRequest::create($data);
    }

    public function updateDetails(ServiceRequest $request, array $data): ServiceRequest
    {
        return $this->change($request, 'update', function (ServiceRequest $locked) use ($data): void {
            $locked->fill($data);
        });
    }

    /**
     * Assign Service Staff and lock in the final schedule. $scheduledStartAt
     * /$scheduledEndAt let the Supervisor override the requester's needed_*
     * window; when omitted, needed_* is carried over as-is. A resolved
     * schedule is required — there is nothing to conflict-check against
     * (see checkStaffAvailability()) without one.
     */
    public function assign(ServiceRequest $request, int $staffId, ?Carbon $scheduledStartAt = null, ?Carbon $scheduledEndAt = null): ServiceRequest
    {
        return $this->change($request, 'assign', function (ServiceRequest $locked) use ($staffId, $scheduledStartAt, $scheduledEndAt): void {
            $staff = User::role('service_staff')->where('status', UserStatus::Active)->find($staffId);
            if (! $staff) {
                throw ValidationException::withMessages(['assigned_to' => 'Select an active Service Staff member.']);
            }
            // Availability status gates assignability outright (On Leave,
            // Official Business, In Training, Out of Office, Absent,
            // Inactive); Available/Busy both remain subject to the
            // datetime overlap check below — staff_status never replaces
            // it, per the spec's own "does not replace conflict detection".
            if (! $staff->staff_status->isAssignable()) {
                throw ValidationException::withMessages(['assigned_to' => $staff->name.' is currently '.$staff->staff_status->label().' and cannot be assigned.']);
            }

            $resolvedStart = $scheduledStartAt ?? $locked->needed_start_at;
            $resolvedEnd = $scheduledEndAt ?? $locked->needed_end_at;
            if (! $resolvedStart || ! $resolvedEnd) {
                throw ValidationException::withMessages(['scheduled_start_at' => 'A scheduled date and time is required to assign staff.']);
            }
            if ($resolvedEnd->lte($resolvedStart)) {
                throw ValidationException::withMessages(['scheduled_end_at' => 'The scheduled end must be later than the scheduled start.']);
            }

            $this->checkStaffAvailability($staffId, $resolvedStart, $resolvedEnd, excludingRequestId: $locked->id);

            $scheduleChanged = ! $resolvedStart->equalTo($locked->needed_start_at ?? $resolvedStart) || ! $resolvedEnd->equalTo($locked->needed_end_at ?? $resolvedEnd);

            $locked->assigned_to = $staffId;
            $locked->status = Status::Assigned;
            $locked->scheduled_start_at = $resolvedStart;
            $locked->scheduled_end_at = $resolvedEnd;
            (new ServiceRequestAssignment)->forceFill([
                'service_request_id' => $locked->id,
                'staff_id' => $staffId,
                'assigned_by' => auth()->id(),
                'assigned_at' => now(),
            ])->save();

            if ($scheduleChanged) {
                ActivityLog::record(
                    $locked,
                    'schedule_overridden',
                    from: $locked->needed_start_at?->toIso8601String().' – '.$locked->needed_end_at?->toIso8601String(),
                    to: $resolvedStart->toIso8601String().' – '.$resolvedEnd->toIso8601String(),
                    remarks: 'Supervisor set a schedule different from the requester\'s original request during assignment.',
                );
            }
        });
    }

    /**
     * Standard overlap rule: existing_start < proposed_end AND existing_end
     * > proposed_start. Only checks requests still holding a live schedule
     * slot — Completed and soft-deleted (cancelled) requests release theirs,
     * and a window that has already ended can never conflict with a future
     * one.
     *
     * Not locked by itself — see checkStaffAvailability() for the
     * transaction-safe version assign() actually enforces. This one is for
     * read-only UI checks (e.g. disabling a staff option before the form is
     * even submitted), where a lock would be pointless and just hold a row
     * lock for no reason.
     */
    public function findScheduleConflict(int $staffId, Carbon $proposedStart, Carbon $proposedEnd, ?int $excludingRequestId = null): ?ServiceRequest
    {
        return ServiceRequest::query()
            ->where('assigned_to', $staffId)
            ->when($excludingRequestId, fn ($query) => $query->whereKeyNot($excludingRequestId))
            ->whereNotIn('status', [Status::Completed])
            ->whereNotNull('scheduled_start_at')
            ->whereNotNull('scheduled_end_at')
            ->where('scheduled_start_at', '<', $proposedEnd)
            ->where('scheduled_end_at', '>', $proposedStart)
            ->first();
    }

    /**
     * The transaction-safe version: run inside assign()'s row-locked
     * transaction so a concurrent assignment can't race past this check.
     */
    private function checkStaffAvailability(int $staffId, Carbon $proposedStart, Carbon $proposedEnd, int $excludingRequestId): void
    {
        $conflict = ServiceRequest::query()
            ->where('assigned_to', $staffId)
            ->whereKeyNot($excludingRequestId)
            ->whereNotIn('status', [Status::Completed])
            ->whereNotNull('scheduled_start_at')
            ->whereNotNull('scheduled_end_at')
            ->where('scheduled_start_at', '<', $proposedEnd)
            ->where('scheduled_end_at', '>', $proposedStart)
            ->lockForUpdate()
            ->first();

        if ($conflict) {
            throw ValidationException::withMessages([
                'assigned_to' => sprintf(
                    'This staff member already has an assignment (%s) scheduled from %s to %s, which overlaps the proposed schedule.',
                    $conflict->request_no,
                    $conflict->scheduled_start_at->toDayDateTimeString(),
                    $conflict->scheduled_end_at->toDayDateTimeString(),
                ),
            ]);
        }
    }

    public function start(ServiceRequest $request): ServiceRequest
    {
        return $this->change($request, 'start', function (ServiceRequest $locked): void {
            $locked->status = Status::InProgress;
        });
    }

    public function updateWork(ServiceRequest $request, string $note): ServiceRequest
    {
        return $this->change($request, 'updateWork', function (ServiceRequest $locked) use ($note): void {
            $locked->completion_note = $this->requiredText('completion_note', $note);
        });
    }

    public function submitForConfirmation(ServiceRequest $request, string $note): ServiceRequest
    {
        return $this->change($request, 'submitForConfirmation', function (ServiceRequest $locked) use ($note): void {
            $locked->completion_note = $this->requiredText('completion_note', $note);
            $locked->status = Status::ForConfirmation;
            $locked->transitionRemarks = $locked->completion_note;
        });
    }

    public function complete(ServiceRequest $request): ServiceRequest
    {
        return $this->change($request, 'complete', function (ServiceRequest $locked): void {
            $locked->status = Status::Completed;
            $locked->completed_at = now();
            $locked->transitionRemarks = $locked->completion_note;
        });
    }

    public function returnForCorrection(ServiceRequest $request, string $reason): ServiceRequest
    {
        return $this->change($request, 'returnForCorrection', function (ServiceRequest $locked) use ($reason): void {
            $locked->transitionRemarks = $this->requiredText('reason', $reason);
            $locked->status = Status::InProgress;
            // Require staff to supply a new note when the correction is ready.
            $locked->completion_note = null;
        });
    }

    public static function validateSave(ServiceRequest $request): void
    {
        if ($request->exists && $request->isDirty('created_by')) {
            throw ValidationException::withMessages(['created_by' => 'The creator cannot be changed.']);
        }

        if (! $request->exists) {
            Gate::authorize('create', ServiceRequest::class);
            if ($request->status !== Status::Submitted || $request->assigned_to || $request->completion_note !== null || $request->completed_at) {
                throw ValidationException::withMessages(['status' => 'New requests must be submitted without assignment or completion data.']);
            }
        } elseif (! self::isWriting($request)) {
            if ($request->isDirty(['status', 'assigned_to', 'completion_note', 'completed_at', 'request_no', 'scheduled_start_at', 'scheduled_end_at'])) {
                throw ValidationException::withMessages(['status' => 'Use an authorized workflow action to change workflow fields.']);
            }
            Gate::authorize('update', $request);
        }

        if ((! $request->exists || $request->isDirty('service_category_id'))
            && ! ServiceCategory::whereKey($request->service_category_id)->where('is_active', true)->exists()) {
            throw ValidationException::withMessages(['service_category_id' => 'Select an active service category.']);
        }
    }

    private function change(ServiceRequest $request, string $ability, Closure $mutate): ServiceRequest
    {
        return DB::transaction(function () use ($request, $ability, $mutate): ServiceRequest {
            $locked = ServiceRequest::query()->lockForUpdate()->findOrFail($request->id);
            Gate::authorize($ability, $locked);
            $mutate($locked);
            self::$writes ??= new WeakMap;
            self::$writes[$locked] = true;
            try {
                $locked->save();
            } finally {
                unset(self::$writes[$locked]);
            }

            return $locked;
        });
    }

    private function requiredText(string $field, string $value): string
    {
        $value = trim($value);
        Validator::make([$field => $value], [$field => ['required', 'string']])->validate();

        return $value;
    }
}
