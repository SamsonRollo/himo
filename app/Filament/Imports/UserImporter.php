<?php

namespace App\Filament\Imports;

use App\Enums\UserStatus;
use App\Models\User;
use Filament\Actions\Imports\ImportColumn;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Models\Import;
use Filament\Forms\Components\Checkbox;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Header-mapped bulk CSV import for user accounts, restricted to Super
 * Admin by UserResource's policy-gated ImportAction. Never creates or
 * promotes an account to super_admin — role is limited to the three
 * domain roles to close a privilege-escalation path via CSV.
 */
class UserImporter extends Importer
{
    protected static ?string $model = User::class;

    public static function getColumns(): array
    {
        return [
            ImportColumn::make('name')
                ->requiredMapping()
                ->rules(['required', 'string', 'max:255']),
            ImportColumn::make('email')
                ->requiredMapping()
                ->rules(['required', 'email', 'max:255']),
            ImportColumn::make('role')
                ->requiredMapping()
                ->rules(['required', Rule::in(['requester', 'service_staff', 'service_supervisor'])])
                // Applied manually in afterSave() via Spatie's role sync, not a
                // plain column, so the default column-fill is a no-op here.
                ->fillRecordUsing(fn () => null),
        ];
    }

    public static function getOptionsFormComponents(): array
    {
        return [
            Checkbox::make('updateExisting')
                ->label('Update existing accounts matched by email')
                ->default(true),
        ];
    }

    public function resolveRecord(): ?Model
    {
        $email = Str::lower(trim((string) ($this->data['email'] ?? '')));
        $existing = User::where('email', $email)->first();

        if ($existing && ! ($this->options['updateExisting'] ?? true)) {
            throw ValidationException::withMessages([
                'email' => 'A user with this email already exists; "Update existing accounts" is off, so this row was skipped.',
            ]);
        }

        if ($existing && $existing->hasRole(config('filament-shield.super_admin.name'))) {
            throw ValidationException::withMessages([
                'email' => 'This email belongs to a Super Admin account, which cannot be modified by CSV import.',
            ]);
        }

        return $existing ?? new User;
    }

    protected function beforeSave(): void
    {
        if (! $this->record->exists) {
            // Imported accounts have no known password; a real one is set via
            // the normal password-reset flow before the account is usable.
            $this->record->password = Hash::make(Str::random(40));
            $this->record->status = UserStatus::Active;
        }
    }

    protected function afterSave(): void
    {
        $this->record->syncRoles([$this->data['role']]);
    }

    public static function getCompletedNotificationBody(Import $import): string
    {
        $body = 'Your user import has completed and '.number_format($import->successful_rows).' '.str('row')->plural($import->successful_rows).' imported.';

        if ($failedRowsCount = $import->getFailedRowsCount()) {
            $body .= ' '.number_format($failedRowsCount).' '.str('row')->plural($failedRowsCount).' failed to import (duplicates without "update existing", invalid roles, or validation errors) — see the downloadable error report.';
        }

        return $body;
    }
}
