<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\ServiceRequests\ServiceRequestResource;
use App\Models\ServiceRequest;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class RecentRequestsWidget extends TableWidget
{
    protected static ?int $sort = 5;

    public static function canView(): bool
    {
        return auth()->user()?->can('View:RecentRequestsWidget') ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(ServiceRequest::visibleTo(auth()->user())->latest('created_at')->limit(5))
            ->heading('Recently submitted requests')
            ->columns(ServiceRequestResource::requestColumns())
            ->defaultSort('created_at', 'desc')
            ->paginated(false);
    }
}
