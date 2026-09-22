<?php

namespace App\Filament\Widgets;

use App\Enums\ServiceRequestStatus as Status;
use App\Models\ServiceRequest;
use App\Models\User;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Institution-wide (Super Admin) / scoped (Service Supervisor) status
 * breakdown, extending the baseline ServiceRequestMetrics with a full
 * per-status count and an accurate average resolution time. Scoped through
 * the same ServiceRequest::visibleTo() query used everywhere else in the
 * panel, so a Supervisor never sees data outside their authorized scope.
 */
class RequestStatusOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    public static function canView(): bool
    {
        return auth()->user()?->can('View:RequestStatusOverview') ?? false;
    }

    /**
     * @return array{total: int, submitted: int, assigned: int, in_progress: int, for_confirmation: int, completed: int, average_resolution_days: float|null}
     */
    public static function counts(?User $user): array
    {
        $counts = ServiceRequest::visibleTo($user)->selectRaw('status, COUNT(*) AS total')->groupBy('status')->pluck('total', 'status');

        // Computed in PHP rather than a driver-specific date-diff SQL
        // function, so this works identically on Postgres, MySQL, and the
        // SQLite connection the test suite runs against.
        $completions = ServiceRequest::visibleTo($user)
            ->where('status', Status::Completed)
            ->whereNotNull('completed_at')
            ->get(['created_at', 'completed_at']);
        $averageResolutionDays = $completions->isNotEmpty()
            ? $completions->avg(fn (ServiceRequest $request) => $request->created_at->diffInMinutes($request->completed_at)) / 1440
            : null;

        return [
            'total' => (int) $counts->sum(),
            'submitted' => (int) ($counts[Status::Submitted->value] ?? 0),
            'assigned' => (int) ($counts[Status::Assigned->value] ?? 0),
            'in_progress' => (int) ($counts[Status::InProgress->value] ?? 0),
            'for_confirmation' => (int) ($counts[Status::ForConfirmation->value] ?? 0),
            'completed' => (int) ($counts[Status::Completed->value] ?? 0),
            'average_resolution_days' => $averageResolutionDays,
        ];
    }

    protected function getStats(): array
    {
        $stats = static::counts(auth()->user());

        return [
            Stat::make('Total requests', $stats['total']),
            // Gold draws the eye to the one bucket that needs action: a
            // submitted request nobody has picked up yet.
            Stat::make('Unassigned (Submitted)', $stats['submitted'])->color('gold'),
            Stat::make('Assigned', $stats['assigned']),
            Stat::make('In progress', $stats['in_progress']),
            Stat::make('For confirmation', $stats['for_confirmation']),
            Stat::make('Completed', $stats['completed']),
            Stat::make('Avg. resolution time', $stats['average_resolution_days'] !== null ? number_format($stats['average_resolution_days'], 1).' days' : 'No completed requests yet'),
        ];
    }
}
