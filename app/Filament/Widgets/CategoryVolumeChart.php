<?php

namespace App\Filament\Widgets;

use App\Models\ServiceCategory;
use Filament\Widgets\ChartWidget;

/**
 * Which categories carry the most request volume, within the viewer's
 * authorized scope. A single grouped aggregate query, not a per-category
 * lookup, so this stays cheap regardless of dataset size.
 */
class CategoryVolumeChart extends ChartWidget
{
    protected ?string $heading = 'Requests by category';

    protected static ?int $sort = 2;

    public static function canView(): bool
    {
        return auth()->user()?->can('View:CategoryVolumeChart') ?? false;
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $counts = ServiceCategory::query()
            ->withCount(['serviceRequests' => fn ($query) => $query->visibleTo(auth()->user())])
            ->orderByDesc('service_requests_count')
            ->get();

        return [
            'datasets' => [[
                'label' => 'Requests',
                'data' => $counts->pluck('service_requests_count')->all(),
                'backgroundColor' => '#7B1113',
            ]],
            'labels' => $counts->pluck('name')->all(),
        ];
    }
}
