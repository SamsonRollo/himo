<button
    type="button"
    aria-controls="fi-main-sidebar"
    aria-label="Toggle sidebar"
    title="Toggle sidebar"
    x-data="{}"
    x-bind:aria-expanded="$store.sidebar.isOpen"
    x-on:click="$store.sidebar.isOpen ? $store.sidebar.close() : $store.sidebar.open()"
    x-tooltip="{ content: 'Toggle sidebar' }"
    class="himo-sidebar-toggle"
>
    <x-filament::icon icon="heroicon-o-bars-3" class="h-6 w-6" />
    <span class="fi-sr-only">Toggle sidebar</span>
</button>
