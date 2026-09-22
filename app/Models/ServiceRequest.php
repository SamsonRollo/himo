<?php

namespace App\Models;

use App\Enums\ServiceRequestStatus;
use App\Models\Concerns\TracksAuthenticatedOwnership;
use App\Services\ServiceRequestWorkflow;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

#[Fillable(['service_category_id', 'location', 'description'])]
class ServiceRequest extends Model
{
    use HasFactory, SoftDeletes, TracksAuthenticatedOwnership;

    protected $attributes = ['status' => 'submitted'];

    public ?string $transitionRemarks = null;

    public function save(array $options = [])
    {
        // A request and its history must either both persist or both roll back.
        return DB::transaction(fn () => parent::save($options));
    }

    protected function casts(): array
    {
        return ['status' => ServiceRequestStatus::class, 'completed_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::deleting(fn (self $request) => Gate::authorize('delete', $request));

        static::creating(function (self $request): void {
            $request->request_no = 'SR-'.Str::ulid();
        });

        static::saving(function (self $request): void {
            $request->location = trim($request->location ?? '');
            $request->description = trim($request->description ?? '');
            if ($request->completion_note !== null) {
                $request->completion_note = trim($request->completion_note);
            }

            Validator::make($request->getAttributes(), [
                'service_category_id' => ['required', 'exists:service_categories,id'],
                'location' => ['required', 'string', 'max:150'],
                'description' => ['required', 'string'],
            ])->validate();

            if ($request->status === ServiceRequestStatus::Completed && (! $request->assigned_to || blank($request->completion_note))) {
                throw ValidationException::withMessages(['completion_note' => 'Completion requires assigned staff and a non-empty completion note.']);
            }

            ServiceRequestWorkflow::validateSave($request);
        });

        static::saved(function (self $request): void {
            $isNew = $request->getRawOriginal('id') === null;
            if ($isNew || $request->isDirty('status')) {
                $history = new ServiceRequestStatusHistory;
                $history->forceFill([
                    'service_request_id' => $request->id,
                    'from_status' => $isNew ? null : $request->getRawOriginal('status'),
                    'to_status' => $request->status->value,
                    'remarks' => $request->transitionRemarks,
                    'changed_by' => auth()->id(),
                ])->save();
                $request->transitionRemarks = null;
            }
        });
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ServiceCategory::class, 'service_category_id')->withTrashed();
    }

    public function scopeVisibleTo(Builder $query, ?User $user): Builder
    {
        if (! $user || ! $user->can('ViewAny:ServiceRequest')) {
            return $query->whereRaw('1 = 0');
        }

        if ($user->hasRole(['service_supervisor', config('filament-shield.super_admin.name')])) {
            return $query;
        }

        return $query->where(function (Builder $query) use ($user): void {
            $query->whereRaw('1 = 0');
            if ($user->hasRole('requester')) {
                $query->orWhere('created_by', $user->id);
            }
            if ($user->hasRole('service_staff')) {
                // Current assignment, plus anything the assignment log shows
                // was ever assigned to this staff member, so a task stays
                // visible in their history even after a future reassignment.
                $query->orWhere('assigned_to', $user->id)
                    ->orWhereHas('assignments', fn (Builder $assignments) => $assignments->where('staff_id', $user->id));
            }
        });
    }

    public function assignedStaff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function statusHistories(): HasMany
    {
        return $this->hasMany(ServiceRequestStatusHistory::class)->orderBy('id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(ServiceRequestAssignment::class)->orderBy('assigned_at');
    }
}
