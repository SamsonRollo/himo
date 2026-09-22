<?php

namespace App\Enums;

/**
 * Service Staff availability status — distinct from UserStatus (account
 * active/inactive), ServiceRequestStatus (workflow status), and
 * ServiceRequestPriority. Manual only: nothing in this application
 * transitions these values automatically (see ServiceRequestWorkflow's
 * assign() and the "no reliable automation" note in the spec this
 * implements).
 */
enum StaffAvailabilityStatus: string
{
    case Available = 'available';
    case Busy = 'busy';
    case OnLeave = 'on_leave';
    case OfficialBusiness = 'official_business';
    case InTraining = 'in_training';
    case OutOfOffice = 'out_of_office';
    case Absent = 'absent';
    case Inactive = 'inactive';

    public function label(): string
    {
        return match ($this) {
            self::Available => 'Available',
            self::Busy => 'Busy',
            self::OnLeave => 'On Leave',
            self::OfficialBusiness => 'Official Business',
            self::InTraining => 'In Training',
            self::OutOfOffice => 'Out of Office',
            self::Absent => 'Absent',
            self::Inactive => 'Inactive',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Available => 'success',
            self::Busy => 'warning',
            self::Inactive => 'gray',
            default => 'danger',
        };
    }

    /**
     * Paired with color() so availability is never communicated by color
     * alone.
     */
    public function icon(): string
    {
        return match ($this) {
            self::Available => 'heroicon-o-check-circle',
            self::Busy => 'heroicon-o-clock',
            self::OnLeave => 'heroicon-o-sun',
            self::OfficialBusiness => 'heroicon-o-briefcase',
            self::InTraining => 'heroicon-o-academic-cap',
            self::OutOfOffice => 'heroicon-o-map-pin',
            self::Absent => 'heroicon-o-question-mark-circle',
            self::Inactive => 'heroicon-o-x-circle',
        };
    }

    /**
     * true: still assignable, subject to the normal schedule-overlap check.
     * false: never assignable while this status applies, regardless of
     * schedule.
     */
    public function isAssignable(): bool
    {
        return match ($this) {
            self::Available, self::Busy => true,
            default => false,
        };
    }

    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $status) => [$status->value => $status->label()])->all();
    }
}
