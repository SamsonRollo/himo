<?php

namespace App\Filament\Resources\Users;

use App\Enums\UserStatus;
use App\Filament\Exports\UserExporter;
use App\Filament\Imports\UserImporter;
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
use Spatie\Permission\Models\Role;
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
            Select::make('roles')
                ->relationship('roles', 'name')
                ->options(fn () => Role::query()->pluck('name', 'name'))
                ->multiple()->maxItems(1)->required()->preload()->searchable()
                ->label('Role'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->searchable()->sortable(),
            TextColumn::make('email')->searchable()->sortable(),
            TextColumn::make('roles.name')->label('Role')->badge(),
            TextColumn::make('status')->badge()
                ->formatStateUsing(fn (UserStatus $state) => $state->label())
                ->color(fn (UserStatus $state) => $state->color()),
            TextColumn::make('created_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
        ])->filters([
            SelectFilter::make('roles')->relationship('roles', 'name'),
            SelectFilter::make('status')->options(['active' => 'Active', 'inactive' => 'Inactive']),
        ])->recordActions([
            EditAction::make(),
            static::toggleStatusAction(),
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

    public static function headerActions(): array
    {
        return [
            ImportAction::make()->importer(UserImporter::class)->modalWidth(Width::Large),
            ExportAction::make()->exporter(UserExporter::class),
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
