@php
    $user = filament()->auth()->user();
    $name = $user?->name ?? 'Guest';
    // Phase 8: every user also holds "requester", which would otherwise
    // dominate this single-role display for a Supervisor/Staff/Super Admin
    // account — primaryRole() prefers their other, more specific role.
    $roleName = $user?->primaryRole()?->name;
    $roleLabel = $roleName ? str($roleName)->replace('_', ' ')->title() : 'No role assigned';
    // Phase 7: "Service Staff may view their own status" — read-only, no
    // edit control here (staff have no reachable UserResource action to
    // change it themselves).
    $ownAvailability = $user?->hasRole('service_staff') ? $user->staff_status : null;
@endphp

{{-- Sits in the topbar's flex row (gap already handled by .fi-topbar-end), immediately before the profile avatar/dropdown trigger. Name is the primary, dark line; role is a smaller, lighter line underneath so it never competes with the avatar for attention. --}}
<div class="hidden sm:flex flex-col items-end justify-center leading-tight max-w-[10rem] md:max-w-[14rem] text-right">
    <span class="text-sm font-semibold text-gray-950 dark:text-white truncate w-full">{{ $name }}</span>
    <span class="text-xs font-normal text-gray-400 dark:text-gray-500 truncate w-full">{{ $roleLabel }}</span>
    @if($ownAvailability)
        <span @class([
            'text-xs font-medium truncate w-full',
            'text-green-600 dark:text-green-400' => $ownAvailability->color() === 'success',
            'text-amber-600 dark:text-amber-400' => $ownAvailability->color() === 'warning',
            'text-red-600 dark:text-red-400' => $ownAvailability->color() === 'danger',
            'text-gray-500 dark:text-gray-400' => $ownAvailability->color() === 'gray',
        ])>{{ $ownAvailability->label() }}</span>
    @endif
</div>
