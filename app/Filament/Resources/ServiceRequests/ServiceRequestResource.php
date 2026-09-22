<?php

namespace App\Filament\Resources\ServiceRequests;

use App\Enums\ServiceRequestPriority as Priority;
use App\Enums\ServiceRequestStatus as Status;
use App\Models\ServiceCategory;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Services\ServiceRequestWorkflow;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use UnitEnum;

class ServiceRequestResource extends Resource
{
    protected static ?string $model = ServiceRequest::class;

    protected static ?string $recordTitleAttribute = 'request_no';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWrenchScrewdriver;

    protected static string|UnitEnum|null $navigationGroup = 'Service Requests';

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
            Select::make('priority')->options(Priority::options())->default(Priority::Normal->value)
                ->required()->native(false)
                ->helperText(fn (?string $state) => (Priority::tryFrom($state ?? '') ?? Priority::Normal)->description()),
            TextInput::make('location')->required()->maxLength(150),
            DateTimePicker::make('needed_start_at')->label('Needed from')->required()->native(false)->seconds(false),
            DateTimePicker::make('needed_end_at')->label('Needed until')->required()->native(false)->seconds(false)
                ->after('needed_start_at'),
            Textarea::make('description')->required()->columnSpanFull(),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('request_no')->label('Request number'),
            TextEntry::make('status')->formatStateUsing(fn (Status $state) => $state->label())->badge()->color(fn (Status $state) => $state->color()),
            TextEntry::make('priority')->formatStateUsing(fn (Priority $state) => $state->label())->badge()->icon(fn (Priority $state) => $state->icon())->color(fn (Priority $state) => $state->color()),
            TextEntry::make('category.name')->label('Service category'),
            TextEntry::make('location'),
            TextEntry::make('needed_start_at')->label('Needed from')->dateTime()->placeholder('Not specified'),
            TextEntry::make('needed_end_at')->label('Needed until')->dateTime()->placeholder('Not specified'),
            TextEntry::make('scheduled_start_at')->label('Scheduled from')->dateTime()->placeholder('Not yet scheduled'),
            TextEntry::make('scheduled_end_at')->label('Scheduled until')->dateTime()->placeholder('Not yet scheduled'),
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
            TextColumn::make('priority')->formatStateUsing(fn (Priority $state) => $state->label())
                ->badge()->icon(fn (Priority $state) => $state->icon())->color(fn (Priority $state) => $state->color())
                ->sortable(query: fn (Builder $query, string $direction) => $query->orderByRaw(
                    'CASE priority '
                    .collect(Priority::cases())
                        ->map(fn (Priority $priority) => "WHEN '{$priority->value}' THEN {$priority->sortRank()}")
                        ->implode(' ')
                    .' END '.($direction === 'desc' ? 'desc' : 'asc')
                )),
            TextColumn::make('status')->formatStateUsing(fn (Status $state) => $state->label())->badge()->color(fn (Status $state) => $state->color())->sortable(),
            TextColumn::make('creator.name')->label('Requester'),
            TextColumn::make('assignedStaff.name')->label('Assigned staff')->placeholder('Unassigned'),
            TextColumn::make('scheduled_start_at')->label('Scheduled')->dateTime()->placeholder('Not scheduled')->sortable()->toggleable(),
            TextColumn::make('completed_at')->dateTime()->sortable()->toggleable(),
        ];
    }

    public static function requestFilters(): array
    {
        return [
            SelectFilter::make('status')->options(Status::options()),
            SelectFilter::make('priority')->options(Priority::options()),
            SelectFilter::make('service_category_id')->label('Category')
                ->options(fn () => ServiceCategory::withTrashed()->orderBy('name')->pluck('name', 'id')),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table->columns(static::requestColumns())->filters(static::requestFilters())
            ->defaultSort('created_at', 'desc')
            ->recordActions([ViewAction::make(), EditAction::make(), ...static::workflowActions(), static::cancelAction()]);
    }

    /**
     * Requester-only: withdraw a request that has not yet been actioned.
     * Soft-deletes under the hood (ServiceRequestPolicy::delete), surfaced
     * with the requester-facing "Cancel request" language from the spec
     * rather than a generic "Delete".
     */
    public static function cancelAction(): Action
    {
        return Action::make('cancel')->label('Cancel request')->color('danger')
            ->icon('heroicon-o-x-circle')->authorize('delete')->requiresConfirmation()
            ->action(fn (ServiceRequest $record) => $record->delete());
    }

    public static function workflowActions(): array
    {
        return [
            Action::make('assign')->label('Assign')->authorize('assign')
                ->fillForm(fn (ServiceRequest $record) => [
                    'scheduled_start_at' => $record->needed_start_at,
                    'scheduled_end_at' => $record->needed_end_at,
                ])
                ->schema([
                    DateTimePicker::make('scheduled_start_at')->label('Scheduled from')->native(false)->seconds(false)
                        ->required()->live()->helperText('Defaults to the requester\'s needed schedule; change it to reschedule.'),
                    DateTimePicker::make('scheduled_end_at')->label('Scheduled until')->native(false)->seconds(false)
                        ->required()->live()->after('scheduled_start_at'),
                    // Staff unavailable either by schedule conflict or by
                    // availability status (On Leave, Inactive, etc.) are
                    // visibly labeled and disabled — not just hidden — so a
                    // Supervisor can see *why* a name is unavailable.
                    Select::make('assigned_to')->label('Service Staff')->required()->searchable()
                        ->options(function (Get $get, ?ServiceRequest $record) {
                            $start = $get('scheduled_start_at');
                            $end = $get('scheduled_end_at');

                            return User::role('service_staff')->active()->orderBy('name')->get()
                                ->mapWithKeys(function (User $staff) use ($start, $end, $record) {
                                    if (! $staff->staff_status->isAssignable()) {
                                        return [$staff->id => $staff->name.' — Unavailable ('.$staff->staff_status->label().')'];
                                    }
                                    $conflict = ($start && $end)
                                        ? app(ServiceRequestWorkflow::class)->findScheduleConflict($staff->id, Carbon::parse($start), Carbon::parse($end), $record?->id)
                                        : null;

                                    return [$staff->id => $conflict
                                        ? $staff->name.' — Unavailable (conflicts with '.$conflict->request_no.')'
                                        : $staff->name];
                                });
                        })
                        ->disableOptionWhen(function (string $value, Get $get, ?ServiceRequest $record) {
                            $staff = User::find((int) $value);
                            if (! $staff || ! $staff->staff_status->isAssignable()) {
                                return true;
                            }
                            $start = $get('scheduled_start_at');
                            $end = $get('scheduled_end_at');
                            if (! $start || ! $end) {
                                return false;
                            }

                            return (bool) app(ServiceRequestWorkflow::class)->findScheduleConflict((int) $value, Carbon::parse($start), Carbon::parse($end), $record?->id);
                        }),
                ])->action(fn (ServiceRequest $record, array $data) => app(ServiceRequestWorkflow::class)->assign(
                    $record,
                    (int) $data['assigned_to'],
                    Carbon::parse($data['scheduled_start_at']),
                    Carbon::parse($data['scheduled_end_at']),
                )),
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
