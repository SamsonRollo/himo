<?php

namespace App\Services;

use App\Enums\ServiceRequestStatus as Status;
use App\Enums\UserStatus;
use App\Models\ServiceCategory;
use App\Models\ServiceRequest;
use App\Models\ServiceRequestAssignment;
use App\Models\User;
use Closure;
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

    public function assign(ServiceRequest $request, int $staffId): ServiceRequest
    {
        return $this->change($request, 'assign', function (ServiceRequest $locked) use ($staffId): void {
            if (! User::role('service_staff')->where('status', UserStatus::Active)->whereKey($staffId)->exists()) {
                throw ValidationException::withMessages(['assigned_to' => 'Select an active Service Staff member.']);
            }
            $locked->assigned_to = $staffId;
            $locked->status = Status::Assigned;
            ServiceRequestAssignment::create([
                'service_request_id' => $locked->id,
                'staff_id' => $staffId,
                'assigned_by' => auth()->id(),
                'assigned_at' => now(),
            ]);
        });
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
            if ($request->isDirty(['status', 'assigned_to', 'completion_note', 'completed_at', 'request_no'])) {
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
