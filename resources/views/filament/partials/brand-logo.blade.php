{{-- UP (leftmost) and HIMO marks side by side in the nav header; each keeps its own natural aspect ratio via object-contain, sized to fill the topbar/sidebar header height (--topbar-height: 4rem) without crowding it. --}}
<div class="flex items-center gap-x-2.5 py-1">
    <img
        src="{{ asset('images/branding/up-logo.png') }}"
        alt="University of the Philippines seal"
        class="h-10 w-auto shrink-0 object-contain"
    />
    <span class="h-8 w-px shrink-0 bg-gray-300 dark:bg-gray-600"></span>
    <img
        src="{{ asset('images/branding/himo-logo.png') }}"
        alt="HIMO — Helpdesk Intake, Management, and Job Orders"
        class="h-9 w-auto shrink-0 object-contain"
    />
</div>
