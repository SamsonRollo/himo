<?php

namespace App\Filament\Resources\Users;

use App\Enums\StaffAvailabilityStatus;
use App\Enums\UserStatus;
use App\Filament\Exports\UserExporter;
use App\Filament\Imports\UserImporter;
use App\Models\ActivityLog;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ExportAction;
use Filament\Actions\ImportAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Hash;
use UnitEnum;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $navigationLabel = 'Users';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|UnitEnum|null $navigationGroup = 'Users & Access';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(255),
            TextInput::make('email')->email()->required()->maxLength(255)->unique(ignoreRecord: true),
            TextInput::make('password')->password()->revealable()
                ->required(fn (string $operation) => $operation === 'create')
                ->dehydrated(fn (?string $state) => filled($state))
                ->dehydrateStateUsing(fn (string $state) => Hash::make($state))
                ->maxLength(255)->visibleOn(['create', 'edit'])
                ->helperText('Leave blank to keep the current password when editing.'),
            // relationship('roles', 'name') already derives id => name
            // options from the pivot; a custom ->options() override here
            // previously returned name => name pairs instead of id => name,
            // which made every submission fail relationship()'s own
            // "must be a valid related record" validation.
            Select::make('roles')
                ->relationship('roles', 'name')
                ->multiple()->maxItems(1)->required()->preload()->searchable()
                ->label('Role'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->searchable()->sortable(),
            TextColumn::make('email')->searchable()->sortable(),
            // Every user also holds "requester" by default (Phase 8); the
            // table shows only the more specific functional role so it
            // doesn't turn into "Requester" for everyone. Use the "Role"
            // filter below to see the full set including Requester.
            TextColumn::make('primary_role')->label('Role')->badge()
                ->getStateUsing(fn (User $record) => $record->primaryRole()?->name)
                ->formatStateUsing(fn (?string $state) => $state ? str($state)->replace('_', ' ')->title() : '—'),
            TextColumn::make('status')->badge()
                ->formatStateUsing(fn (UserStatus $state) => $state->label())
                ->color(fn (UserStatus $state) => $state->color()),
            TextColumn::make('staff_status')->label('Availability')
                ->formatStateUsing(fn (User $record, StaffAvailabilityStatus $state) => $record->hasRole('service_staff') ? $state->label() : '—')
                ->badge(fn (User $record) => $record->hasRole('service_staff'))
                ->icon(fn (User $record, StaffAvailabilityStatus $state) => $record->hasRole('service_staff') ? $state->icon() : null)
                ->color(fn (User $record, StaffAvailabilityStatus $state) => $record->hasRole('service_staff') ? $state->color() : 'gray'),
            TextColumn::make('created_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
        ])->filters([
            SelectFilter::make('roles')->relationship('roles', 'name'),
            SelectFilter::make('status')->options(['active' => 'Active', 'inactive' => 'Inactive']),
            SelectFilter::make('staff_status')->label('Availability')->options(StaffAvailabilityStatus::options()),
        ])->recordActions([
            EditAction::make(),
            static::toggleStatusAction(),
            static::updateStaffStatusAction(),
        ]);
    }

    /**
     * Deactivate/reactivate, never a real delete — see UserPolicy::delete
     * for the non-destructive semantics and the last-super-admin guard.
     */
    public static function toggleStatusAction(): Action
    {
        return Action::make('toggleStatus')
            ->label(fn (User $record) => $record->status === UserStatus::Active ? 'Deactivate' : 'Reactivate')
            ->color(fn (User $record) => $record->status === UserStatus::Active ? 'danger' : 'success')
            ->icon(fn (User $record) => $record->status === UserStatus::Active ? 'heroicon-o-user-minus' : 'heroicon-o-user-plus')
            ->authorize('delete')
            ->requiresConfirmation()
            ->action(function (User $record): void {
                if ($record->status === UserStatus::Active) {
                    $record->forceFill([
                        'status' => UserStatus::Inactive,
                        'deactivated_at' => now(),
                        'deactivated_by' => auth()->id(),
                    ])->save();
                } else {
                    $record->forceFill([
                        'status' => UserStatus::Active,
                        'deactivated_at' => null,
                        'deactivated_by' => null,
                    ])->save();
                }
            });
    }

    /**
     * Narrower than full user management: a Supervisor may change a Service
     * Staff member's availability without the general Update:User
     * permission that would also open name/email/password/role editing.
     */
    public static function updateStaffStatusAction(): Action
    {
        return Action::make('updateStaffStatus')->label('Set availability')
            ->icon('heroicon-o-signal')
            ->visible(fn (User $record) => $record->hasRole('service_staff') && (auth()->user()?->can('Update:StaffStatus') ?? false))
            ->fillForm(fn (User $record) => ['staff_status' => $record->staff_status->value])
            ->schema([
                Select::make('staff_status')->label('Availability')
                    ->options(StaffAvailabilityStatus::options())->required()->native(false),
            ])
            ->action(function (User $record, array $data): void {
                $from = $record->staff_status->value;
                $record->forceFill(['staff_status' => $data['staff_status']])->save();
                if ($from !== $data['staff_status']) {
                    ActivityLog::record($record, 'staff_status_changed', $from, $data['staff_status']);
                }
            });
    }

    public static function headerActions(): array
    {
        return [
            ImportAction::make()->importer(UserImporter::class)->modalWidth(Width::Large)
                ->authorize(fn () => auth()->user()?->can('Create:User') ?? false),
            ExportAction::make()->exporter(UserExporter::class)
                ->authorize(fn () => auth()->user()?->can('Create:User') ?? false),
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }
}
