<?php

namespace App\Models;

use App\Models\Concerns\TracksAuthenticatedOwnership;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Validator;

#[Fillable(['name', 'description', 'is_active'])]
class ServiceCategory extends Model
{
    use HasFactory, SoftDeletes, TracksAuthenticatedOwnership;

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::saving(function (self $category): void {
            $category->name = trim($category->name ?? '');
            Validator::make($category->getAttributes(), [
                'name' => ['required', 'string', 'max:100'],
                'description' => ['nullable', 'string'],
                'is_active' => ['sometimes', 'boolean'],
            ])->validate();
        });
    }

    public function serviceRequests(): HasMany
    {
        return $this->hasMany(ServiceRequest::class);
    }
}
