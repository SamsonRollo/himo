<?php

namespace App\Filament\Pages;

use App\Enums\ServiceRequestPriority as Priority;
use App\Enums\ServiceRequestStatus as Status;
use App\Filament\Resources\ServiceRequests\ServiceRequestResource;
use App\Models\ServiceCategory;
use App\Models\ServiceRequest;
use App\Models\User;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * Calendar of scheduled service requests. Reused for both Phase 4
 * (Supervisor/Super Admin: full visibility, Services/Staff view toggle,
 * every filter) and Phase 5 (Service Staff: their own assignments only) by
 * riding the same ServiceRequest::visibleTo() scope every other view in the
 * panel already uses, rather than building a parallel authorization path.
 */
class ServiceCalendar extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static string|UnitEnum|null $navigationGroup = 'Service Requests';

    protected static ?string $navigationLabel = 'Calendar';

    protected static ?int $navigationSort = 1;

    protected static ?string $title = 'Service calendar';

    protected string $view = 'filament.pages.service-calendar';

    /** 'services' shows every scheduled request; 'staff' requires picking one staff member. */
    #[Url]
    public string $viewMode = 'services';

    #[Url]
    public ?int $staffFilter = null;

    #[Url]
    public ?int $categoryFilter = null;

    #[Url]
    public ?string $priorityFilter = null;

    #[Url]
    public ?string $statusFilter = null;

    /**
     * Calendar audience is Service Staff, Service Supervisor, and Super
     * Admin — narrower than ViewAny:ServiceRequest, which Requester also
     * holds (for their own request list) but should not get a calendar.
     */
    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole(['service_staff', 'service_supervisor', config('filament-shield.super_admin.name')]) ?? false;
    }

    public function canManageStaffView(): bool
    {
        return auth()->user()?->hasRole(['service_supervisor', config('filament-shield.super_admin.name')]) ?? false;
    }

    public function mount(): void
    {
        if (! $this->canManageStaffView()) {
            $this->viewMode = 'services';
        }
    }

    public function staffOptions(): array
    {
        return User::role('service_staff')->orderBy('name')->pluck('name', 'id')->all();
    }

    public function categoryOptions(): array
    {
        return ServiceCategory::orderBy('name')->pluck('name', 'id')->all();
    }

    /**
     * Called from the Blade view's Alpine component to fetch events for the
     * currently selected filters. Server-scoped via visibleTo(), so a
     * Service Staff member can never fetch another user's schedule by
     * manipulating the client-side filter state.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getCalendarEvents(): array
    {
        $user = auth()->user();
        $query = ServiceRequest::visibleTo($user)
            ->whereNotNull('scheduled_start_at')
            ->whereNotNull('scheduled_end_at')
            ->with(['category', 'assignedStaff']);

        if ($this->canManageStaffView()) {
            if ($this->staffFilter) {
                $query->where('assigned_to', $this->staffFilter);
            }
            if ($this->categoryFilter) {
                $query->where('service_category_id', $this->categoryFilter);
            }
        }

        if ($this->priorityFilter) {
            $query->where('priority', $this->priorityFilter);
        }
        if ($this->statusFilter) {
            $query->where('status', $this->statusFilter);
        }

        // Staff view with nothing picked yet: show nothing rather than
        // silently falling back to "everyone", which would defeat the point
        // of the per-staff view for a Supervisor comparing individual loads.
        if ($this->canManageStaffView() && $this->viewMode === 'staff' && ! $this->staffFilter) {
            return [];
        }

        return $query->get()->map(fn (ServiceRequest $request) => [
            'id' => $request->id,
            // Priority spelled out in the visible label, not just conveyed
            // by event color, so it reads without relying on color alone.
            'title' => '['.$request->priority->label().'] '.$request->request_no.' — '.$request->category?->name,
            'start' => $request->scheduled_start_at->toIso8601String(),
            'end' => $request->scheduled_end_at->toIso8601String(),
            'color' => match ($request->priority) {
                Priority::Critical, Priority::Urgent => '#7B1113',
                Priority::High => '#FFC72C',
                default => '#014421',
            },
            'url' => ServiceRequestResource::getUrl('view', ['record' => $request->id]),
            'extendedProps' => [
                'status' => $request->status->label(),
                'priority' => $request->priority->label(),
                'staff' => $request->assignedStaff?->name ?? 'Unassigned',
                'category' => $request->category?->name,
            ],
        ])->all();
    }

    public function priorityOptions(): array
    {
        return Priority::options();
    }

    public function statusOptions(): array
    {
        return Status::options();
    }
}
