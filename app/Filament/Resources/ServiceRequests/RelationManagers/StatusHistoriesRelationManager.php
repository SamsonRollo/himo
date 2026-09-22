<?php

namespace App\Filament\Resources\ServiceRequests\RelationManagers;

use App\Enums\ServiceRequestStatus as Status;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

class StatusHistoriesRelationManager extends RelationManager
{
    protected static string $relationship = 'statusHistories';

    protected static ?string $title = 'Status history';

    public function mount(): void
    {
        Gate::authorize('view', $this->getOwnerRecord());
        parent::mount();
    }

    public function hydrate(): void
    {
        Gate::authorize('view', $this->getOwnerRecord());
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can('view', $ownerRecord) ?? false;
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn (Builder $query) => $query->whereHas('serviceRequest', fn (Builder $requests) => $requests->visibleTo(auth()->user())))
            ->columns([
                TextColumn::make('created_at')->label('When')->dateTime(),
                TextColumn::make('changedBy.name')->label('Changed by'),
                TextColumn::make('from_status')->label('From')->formatStateUsing(fn (?Status $state) => $state?->label())->placeholder('New request'),
                TextColumn::make('to_status')->label('To')->formatStateUsing(fn (Status $state) => $state->label())->badge(),
                TextColumn::make('remarks')->wrap(),
            ])->defaultSort('id', 'asc');
    }
}
