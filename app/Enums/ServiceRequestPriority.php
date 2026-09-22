<?php

namespace App\Enums;

enum ServiceRequestPriority: string
{
    case Low = 'low';
    case Normal = 'normal';
    case High = 'high';
    case Urgent = 'urgent';
    case Critical = 'critical';

    public function label(): string
    {
        return match ($this) {
            self::Low => 'Low',
            self::Normal => 'Normal',
            self::High => 'High',
            self::Urgent => 'Urgent',
            self::Critical => 'Critical',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Low => 'gray',
            self::Normal => 'info',
            self::High => 'warning',
            self::Urgent => 'danger',
            self::Critical => 'gold',
        };
    }

    /**
     * Paired with color() so priority is never communicated by color alone.
     */
    public function icon(): string
    {
        return match ($this) {
            self::Low => 'heroicon-o-arrow-down',
            self::Normal => 'heroicon-o-minus',
            self::High => 'heroicon-o-arrow-up',
            self::Urgent => 'heroicon-o-exclamation-triangle',
            self::Critical => 'heroicon-o-fire',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Low => 'Minor request with little operational impact; normal work can continue.',
            self::Normal => 'Standard service request affecting routine work but with an available workaround.',
            self::High => 'Significant issue affecting important work or multiple users; limited workaround exists.',
            self::Urgent => 'Major disruption affecting essential operations; immediate attention is required.',
            self::Critical => 'Campus-wide or safety/security-related disruption affecting mission-critical services.',
        };
    }

    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $priority) => [$priority->value => $priority->label()])->all();
    }

    /**
     * Severity rank, lowest first, so sorting ascending by rank places
     * Critical on top and Low at the bottom regardless of alphabetical
     * enum value order.
     */
    public function sortRank(): int
    {
        return match ($this) {
            self::Critical => 0,
            self::Urgent => 1,
            self::High => 2,
            self::Normal => 3,
            self::Low => 4,
        };
    }
}
