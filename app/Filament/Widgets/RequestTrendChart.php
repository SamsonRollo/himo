<?php

namespace App\Filament\Widgets;

use App\Enums\ServiceRequestStatus as Status;
use App\Models\ServiceRequest;
use Filament\Widgets\ChartWidget;

/**
 * Request volume and the principal current-status groups per day over the
 * last 30 days, within the viewer's authorized scope. One grouped aggregate
 * query keeps all five lines aligned to the same date buckets.
 */
class RequestTrendChart extends ChartWidget
{
    protected ?string $heading = 'Requests';

    protected int|string|array $columnSpan = 'full';

    protected ?string $maxHeight = '27rem';

    protected static ?int $sort = 3;

    public static function canView(): bool
    {
        return auth()->user()?->can('View:RequestTrendChart') ?? false;
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getData(): array
    {
        $since = now()->subDays(29)->startOfDay();

        $countsByDay = ServiceRequest::visibleTo(auth()->user())
            ->where('created_at', '>=', $since)
            ->selectRaw('DATE(created_at) AS day, status, COUNT(*) AS total')
            ->groupBy('day', 'status')
            ->get()
            ->groupBy('day')
            ->map(fn ($counts) => $counts->mapWithKeys(fn ($count) => [$count->status->value => (int) $count->total]));

        $labels = [];
        $series = [
            'Volume' => [],
            'Assigned' => [],
            'Unassigned' => [],
            'In progress' => [],
            'Completed' => [],
        ];

        for ($date = $since->copy(); $date->lte(now()); $date->addDay()) {
            $key = $date->toDateString();
            $labels[] = $date->format('M j');
            $counts = $countsByDay->get($key, collect());
            $series['Volume'][] = $counts->sum();
            $series['Assigned'][] = (int) ($counts[Status::Assigned->value] ?? 0);
            $series['Unassigned'][] = (int) ($counts[Status::Submitted->value] ?? 0);
            $series['In progress'][] = (int) ($counts[Status::InProgress->value] ?? 0);
            $series['Completed'][] = (int) ($counts[Status::Completed->value] ?? 0);
        }

        return [
            'datasets' => [
                ['label' => 'Volume', 'data' => $series['Volume'], 'borderColor' => '#7B1113', 'fill' => false],
                ['label' => 'Assigned', 'data' => $series['Assigned'], 'borderColor' => '#0EA5E9', 'fill' => false],
                ['label' => 'Unassigned', 'data' => $series['Unassigned'], 'borderColor' => '#FFC72C', 'fill' => false],
                ['label' => 'In progress', 'data' => $series['In progress'], 'borderColor' => '#F59E0B', 'fill' => false],
                ['label' => 'Completed', 'data' => $series['Completed'], 'borderColor' => '#014421', 'fill' => false],
            ],
            'labels' => $labels,
        ];
    }
}
