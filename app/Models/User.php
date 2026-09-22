<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\UserStatus;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable, SoftDeletes;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'status' => UserStatus::class,
            'deactivated_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(function (self $user): void {
            if ($user->isForceDeleting()) {
                throw ValidationException::withMessages(['user' => 'Permanent deletion is not supported; deactivate the account instead.']);
            }
        });
    }

    /**
     * Gate entry to a Filament panel. Shield's permissions decide what a user
     * may do once inside; this only decides who may open the panel at all, so
     * an account with no role assigned, or a deactivated account, cannot
     * reach the admin area even with a live session.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->status === UserStatus::Active && $this->roles()->exists();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', UserStatus::Active);
    }

    public function deactivatedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'deactivated_by');
    }

    public function assignmentHistory(): HasMany
    {
        return $this->hasMany(ServiceRequestAssignment::class, 'staff_id')->orderByDesc('assigned_at');
    }
}
