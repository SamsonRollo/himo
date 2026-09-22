<?php

namespace App\Filament\Widgets;

use App\Enums\ServiceRequestStatus as Status;
use App\Filament\Resources\ServiceRequests\ServiceRequestResource;
use App\Models\ServiceRequest;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class RecentlyCompletedWidget extends TableWidget
{
    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = 6;

    public static function canView(): bool
    {
        return auth()->user()?->can('View:RecentlyCompletedWidget') ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(ServiceRequest::visibleTo(auth()->user())->where('status', Status::Completed)->latest('completed_at')->limit(5))
            ->heading('Recently completed requests')
            ->columns(ServiceRequestResource::requestColumns())
            ->defaultSort('completed_at', 'desc')
            ->paginated(false);
    }
}
