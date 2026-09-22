<div
    aria-label="University of the Philippines and HIMO"
    class="himo-brand-row himo-navbar-branding shrink-0"
    style="height: calc(var(--topbar-height) - 10px); margin: 0 0 0 15px; padding: 0;"
>
    <x-filament::icon-button
        color="gray"
        icon="heroicon-o-bars-3"
        icon-size="lg"
        label="Toggle sidebar"
        tooltip="Toggle sidebar"
        x-data="{}"
        x-bind:aria-expanded="$store.sidebar.isOpen"
        x-on:click="$store.sidebar.isOpen ? $store.sidebar.close() : $store.sidebar.open()"
        aria-controls="fi-main-sidebar"
        class="himo-navbar-sidebar-toggle"
    />
    <img
        src="{{ asset('images/branding/up-logo.png') }}"
        alt="University of the Philippines seal"
        style="height: 100%; width: auto; margin: 0 5px 0 0; object-fit: contain;"
    />
    <img
        src="{{ asset('images/branding/himo-logo.png') }}"
        alt="HIMO — Helpdesk Intake, Management, and Job Orders"
        style="height: 100%; width: auto; object-fit: contain;"
    />
</div>
