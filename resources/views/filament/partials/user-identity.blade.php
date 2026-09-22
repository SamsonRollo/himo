@php
    $user = filament()->auth()->user();
    $name = $user?->name ?? 'Guest';
    $roleName = $user?->roles?->first()?->name;
    $roleLabel = $roleName ? str($roleName)->replace('_', ' ')->title() : 'No role assigned';
@endphp

{{-- Sits in the topbar's flex row (gap already handled by .fi-topbar-end), immediately before the profile avatar/dropdown trigger. Name is the primary, dark line; role is a smaller, lighter line underneath so it never competes with the avatar for attention. --}}
<div class="hidden sm:flex flex-col items-end justify-center leading-tight max-w-[10rem] md:max-w-[14rem] text-right">
    <span class="text-sm font-semibold text-gray-950 dark:text-white truncate w-full">{{ $name }}</span>
    <span class="text-xs font-normal text-gray-400 dark:text-gray-500 truncate w-full">{{ $roleLabel }}</span>
</div>
