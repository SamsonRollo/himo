<?php

namespace App\Enums;

enum ServiceRequestStatus: string
{
    case Submitted = 'submitted';
    case Assigned = 'assigned';
    case InProgress = 'in_progress';
    case ForConfirmation = 'for_confirmation';
    case Completed = 'completed';

    public function label(): string
    {
        return match ($this) {
            self::Submitted => 'Submitted',
            self::Assigned => 'Assigned',
            self::InProgress => 'In Progress',
            self::ForConfirmation => 'For Confirmation',
            self::Completed => 'Completed',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Submitted => 'gray',
            self::Assigned => 'info',
            self::InProgress => 'warning',
            self::ForConfirmation => 'primary',
            self::Completed => 'success',
        };
    }

    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $status) => [$status->value => $status->label()])->all();
    }
}
