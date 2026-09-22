{{--
    Quick-access shortcut for the "Priority" column's existing sort
    (App\Filament\Resources\ServiceRequests\ServiceRequestResource::requestColumns()),
    which ranks by App\Enums\ServiceRequestPriority::sortRank() rather than
    alphabetically. A plain on/off toggle: the column header still cycles
    asc/desc/off on repeated clicks, but this button only ever turns the
    Critical-first sort on or clears it — clicking the active button must
    not fall through to sortTable()'s desc step, which would leave the
    button looking "on" while flipping the order underneath it.
--}}
<div x-data wire:key="priority-sort-toggle">
    <template x-if="($wire.tableSort || '').startsWith('priority')">
        <x-filament::button
            color="primary"
            icon="heroicon-o-fire"
            wire:click="$set('tableSort', null)"
            wire:loading.attr="disabled"
            wire:target="$set"
            tooltip="{{ __('Sorted by priority: Critical first — click to clear') }}"
        >
            {{ __('Priority') }}
        </x-filament::button>
    </template>

    <template x-if="!(($wire.tableSort || '').startsWith('priority'))">
        <x-filament::button
            color="gray"
            outlined
            icon="heroicon-o-fire"
            wire:click="sortTable('priority', 'asc')"
            wire:loading.attr="disabled"
            wire:target="sortTable('priority', 'asc')"
            tooltip="{{ __('Sort by priority: Critical first') }}"
        >
            {{ __('Priority') }}
        </x-filament::button>
    </template>
</div>
