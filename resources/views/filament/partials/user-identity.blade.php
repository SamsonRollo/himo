@php
    $user = filament()->auth()->user();
    $name = $user?->name ?? 'Guest';
    // Phase 8: every user also holds "requester", which would otherwise
    // dominate this single-role display for a Supervisor/Staff/Super Admin
    // account — primaryRole() prefers their other, more specific role.
    $roleName = $user?->primaryRole()?->name;
    $roleLabel = match ($roleName) {
        'super_admin', 'super-admin' => 'Super Administrator',
        'service_supervisor' => 'Service Supervisor',
        'service_staff' => 'Service Staff',
        'requester' => 'Requester',
        null => 'No role assigned',
        default => str($roleName)->replace(['_', '-'], ' ')->title(),
    };
@endphp

{{-- Sits in the topbar's flex row (gap already handled by .fi-topbar-end), immediately before the profile avatar/dropdown trigger. Name is the primary, dark line; role is a smaller, lighter line underneath so it never competes with the avatar for attention. --}}
<div class="himo-user-identity" aria-label="Authenticated user identity">
    <div class="himo-user-name">{{ $name }}</div>
    <div class="himo-user-role">{{ $roleLabel }}</div>
</div>
