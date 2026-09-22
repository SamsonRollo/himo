<?php

namespace App\Filament\Widgets;

use App\Enums\ServiceRequestStatus as Status;
use App\Models\ServiceRequest;
use App\Models\User;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class ServiceRequestMetrics extends StatsOverviewWidget
{
    protected static ?int $sort = -1;

    public static function canView(): bool
    {
        return auth()->user()?->can('View:ServiceRequestMetrics') ?? false;
    }

    public static function counts(User $user): array
    {
        $counts = ServiceRequest::visibleTo($user)->selectRaw('status, COUNT(*) AS total')->groupBy('status')->pluck('total', 'status');

        return [
            'Open requests' => (int) $counts->except(Status::Completed->value)->sum(),
            'Assigned requests' => (int) ($counts[Status::Assigned->value] ?? 0),
            'In-progress requests' => (int) ($counts[Status::InProgress->value] ?? 0),
            'Completed requests' => (int) ($counts[Status::Completed->value] ?? 0),
        ];
    }

    protected function getStats(): array
    {
        return collect(static::counts(auth()->user()))->map(fn (int $count, string $label) => Stat::make($label, $count))->all();
    }
}
