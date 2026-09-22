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
        $counts = static::counts(auth()->user());

        return [
            Stat::make('Open requests', $counts['Open requests'])->extraAttributes(['class' => 'himo-stat-bottom-border himo-stat-bottom-border-primary']),
            Stat::make('Assigned requests', $counts['Assigned requests'])->extraAttributes(['class' => 'himo-stat-bottom-border himo-stat-bottom-border-info']),
            Stat::make('In-progress requests', $counts['In-progress requests'])->extraAttributes(['class' => 'himo-stat-bottom-border himo-stat-bottom-border-warning']),
            Stat::make('Completed requests', $counts['Completed requests'])->extraAttributes(['class' => 'himo-stat-bottom-border himo-stat-bottom-border-success']),
        ];
    }
}
