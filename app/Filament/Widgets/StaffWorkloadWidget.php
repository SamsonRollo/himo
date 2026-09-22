<?php

namespace App\Filament\Widgets;

use App\Enums\ServiceRequestStatus as Status;
use App\Models\User;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * How many open vs. completed requests each Service Staff member currently
 * carries. The active/completed counts are subquery aggregates via
 * withCount(), so this stays a single query regardless of staff count.
 */
class StaffWorkloadWidget extends TableWidget
{
    protected static ?int $sort = 4;

    public static function canView(): bool
    {
        return auth()->user()?->can('View:StaffWorkloadWidget') ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                User::role('service_staff')
                    ->withCount([
                        'assignedRequests as active_count' => fn ($query) => $query->whereIn('status', [Status::Assigned, Status::InProgress, Status::ForConfirmation]),
                        'assignedRequests as completed_count' => fn ($query) => $query->where('status', Status::Completed),
                    ]),
            )
            ->heading('Staff workload')
            ->columns([
                TextColumn::make('name')->label('Service staff')->sortable(),
                TextColumn::make('active_count')->label('Active assignments')->sortable(),
                TextColumn::make('completed_count')->label('Completed')->sortable(),
            ])
            ->defaultSort('active_count', 'desc')
            ->paginated(false);
    }
}
