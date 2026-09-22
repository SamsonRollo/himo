<?php

namespace App\Filament\Pages;

use App\Filament\Resources\ServiceRequests\ServiceRequestResource;
use App\Models\ServiceRequest;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

class ServiceAccomplishmentReport extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.service-accomplishment-report';

    protected static ?string $title = 'Service accomplishment report';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('View:ServiceAccomplishmentReport') ?? false;
    }

    public function table(Table $table): Table
    {
        return $table->query(ServiceRequest::query()->visibleTo(auth()->user()))
            ->columns([
                ...ServiceRequestResource::requestColumns(),
                TextColumn::make('completion_note')->wrap(),
            ])
            ->filters(ServiceRequestResource::requestFilters())
            ->defaultSort('created_at', 'desc');
    }
}
