<?php

namespace App\Filament\Widgets;

use App\Models\ServiceRequest;
use Filament\Widgets\ChartWidget;

/**
 * Requests submitted per day over the last 30 days, within the viewer's
 * authorized scope. One grouped aggregate query; day buckets with zero
 * submissions are filled in afterwards so the line doesn't skip gaps.
 */
class RequestTrendChart extends ChartWidget
{
    protected ?string $heading = 'Request volume (last 30 days)';

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

        $counts = ServiceRequest::visibleTo(auth()->user())
            ->where('created_at', '>=', $since)
            ->selectRaw('DATE(created_at) AS day, COUNT(*) AS total')
            ->groupBy('day')
            ->pluck('total', 'day');

        $labels = [];
        $data = [];
        for ($date = $since->copy(); $date->lte(now()); $date->addDay()) {
            $key = $date->toDateString();
            $labels[] = $date->format('M j');
            $data[] = (int) ($counts[$key] ?? 0);
        }

        return [
            'datasets' => [[
                'label' => 'Requests submitted',
                'data' => $data,
                'borderColor' => '#7B1113',
                'backgroundColor' => 'rgba(123, 17, 19, 0.1)',
                'fill' => true,
            ]],
            'labels' => $labels,
        ];
    }
}
