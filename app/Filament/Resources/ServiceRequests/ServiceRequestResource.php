<?php

namespace App\Filament\Resources\ServiceRequests;

use App\Enums\ServiceRequestStatus as Status;
use App\Models\ServiceCategory;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Services\ServiceRequestWorkflow;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ServiceRequestResource extends Resource
{
    protected static ?string $model = ServiceRequest::class;

    protected static ?string $recordTitleAttribute = 'request_no';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->visibleTo(auth()->user());
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('service_category_id')->label('Service category')
                ->options(fn () => ServiceCategory::where('is_active', true)->orderBy('name')->pluck('name', 'id'))
                ->searchable()->required(),
            TextInput::make('location')->required()->maxLength(150),
            Textarea::make('description')->required()->columnSpanFull(),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('request_no')->label('Request number'),
            TextEntry::make('status')->formatStateUsing(fn (Status $state) => $state->label())->badge()->color(fn (Status $state) => $state->color()),
            TextEntry::make('category.name')->label('Service category'),
            TextEntry::make('location'),
            TextEntry::make('description')->columnSpanFull(),
            TextEntry::make('creator.name')->label('Requester'),
            TextEntry::make('assignedStaff.name')->label('Assigned staff')->placeholder('Unassigned'),
            TextEntry::make('completion_note')->placeholder('No work note yet')->columnSpanFull(),
            TextEntry::make('created_at')->dateTime(),
            TextEntry::make('completed_at')->dateTime()->placeholder('Not completed'),
        ]);
    }

    public static function requestColumns(): array
    {
        return [
            TextColumn::make('request_no')->label('Request number')->searchable()->sortable(),
            TextColumn::make('category.name')->label('Category')->sortable(),
            TextColumn::make('location')->searchable(),
            TextColumn::make('description')->limit(45)->searchable()->toggleable(),
            TextColumn::make('status')->formatStateUsing(fn (Status $state) => $state->label())->badge()->color(fn (Status $state) => $state->color())->sortable(),
            TextColumn::make('creator.name')->label('Requester'),
            TextColumn::make('assignedStaff.name')->label('Assigned staff')->placeholder('Unassigned'),
            TextColumn::make('completed_at')->dateTime()->sortable()->toggleable(),
        ];
    }

    public static function requestFilters(): array
    {
        return [
            SelectFilter::make('status')->options(Status::options()),
            SelectFilter::make('service_category_id')->label('Category')
                ->options(fn () => ServiceCategory::withTrashed()->orderBy('name')->pluck('name', 'id')),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table->columns(static::requestColumns())->filters(static::requestFilters())
            ->defaultSort('created_at', 'desc')
            ->recordActions([ViewAction::make(), EditAction::make(), ...static::workflowActions(), DeleteAction::make()]);
    }

    public static function workflowActions(): array
    {
        return [
            Action::make('assign')->label('Assign')->authorize('assign')
                ->schema([
                    Select::make('assigned_to')->label('Service Staff')->required()->searchable()
                        ->options(fn () => User::role('service_staff')->orderBy('name')->pluck('name', 'id')),
                ])->action(fn (ServiceRequest $record, array $data) => app(ServiceRequestWorkflow::class)->assign($record, (int) $data['assigned_to'])),
            Action::make('start')->label('Start work')->authorize('start')->requiresConfirmation()
                ->action(fn (ServiceRequest $record) => app(ServiceRequestWorkflow::class)->start($record)),
            Action::make('updateWork')->label('Update work note')->authorize('updateWork')
                ->fillForm(fn (ServiceRequest $record) => ['completion_note' => $record->completion_note])
                ->schema([Textarea::make('completion_note')->label('Work / completion note')->required()])
                ->action(fn (ServiceRequest $record, array $data) => app(ServiceRequestWorkflow::class)->updateWork($record, $data['completion_note'])),
            Action::make('submitForConfirmation')->label('For confirmation')->authorize('submitForConfirmation')
                ->fillForm(fn (ServiceRequest $record) => ['completion_note' => $record->completion_note])
                ->schema([Textarea::make('completion_note')->required()])
                ->action(fn (ServiceRequest $record, array $data) => app(ServiceRequestWorkflow::class)->submitForConfirmation($record, $data['completion_note'])),
            Action::make('complete')->label('Complete')->color('success')->authorize('complete')->requiresConfirmation()
                ->action(fn (ServiceRequest $record) => app(ServiceRequestWorkflow::class)->complete($record)),
            Action::make('returnForCorrection')->label('Return for correction')->color('warning')->authorize('returnForCorrection')
                ->schema([Textarea::make('reason')->required()])
                ->action(fn (ServiceRequest $record, array $data) => app(ServiceRequestWorkflow::class)->returnForCorrection($record, $data['reason'])),
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListServiceRequests::route('/'),
            'create' => Pages\CreateServiceRequest::route('/create'),
            'view' => Pages\ViewServiceRequest::route('/{record}'),
            'edit' => Pages\EditServiceRequest::route('/{record}/edit'),
        ];
    }

    public static function getRelations(): array
    {
        return [RelationManagers\StatusHistoriesRelationManager::class];
    }
}
