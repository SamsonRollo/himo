<?php

namespace App\Filament\Pages;

use App\Filament\Resources\ServiceRequests\ServiceRequestResource;
use App\Models\ServiceRequest;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use UnitEnum;

class ServiceAccomplishmentReport extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.service-accomplishment-report';

    protected static ?string $title = 'Service accomplishment report';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentChartBar;

    protected static string|UnitEnum|null $navigationGroup = 'Reports & Monitoring';

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
