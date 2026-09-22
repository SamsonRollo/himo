<x-filament-panels::page>
    <div class="flex flex-wrap items-end gap-4">
        @if($this->canManageStaffView())
            <div class="min-w-[10rem]">
                <label class="fi-fo-field-wrp-label text-sm font-medium text-gray-950 dark:text-white">View</label>
                <x-filament::input.select wire:model.live="viewMode">
                    <option value="services">Services view</option>
                    <option value="staff">Service Staff view</option>
                </x-filament::input.select>
            </div>

            @if($viewMode === 'staff')
                <div class="min-w-[12rem]">
                    <label class="fi-fo-field-wrp-label text-sm font-medium text-gray-950 dark:text-white">Staff member</label>
                    <x-filament::input.select wire:model.live="staffFilter">
                        <option value="">Select a staff member…</option>
                        @foreach($this->staffOptions() as $id => $name)
                            <option value="{{ $id }}">{{ $name }}</option>
                        @endforeach
                    </x-filament::input.select>
                </div>
            @else
                <div class="min-w-[12rem]">
                    <label class="fi-fo-field-wrp-label text-sm font-medium text-gray-950 dark:text-white">Staff</label>
                    <x-filament::input.select wire:model.live="staffFilter">
                        <option value="">All staff</option>
                        @foreach($this->staffOptions() as $id => $name)
                            <option value="{{ $id }}">{{ $name }}</option>
                        @endforeach
                    </x-filament::input.select>
                </div>
            @endif

            <div class="min-w-[12rem]">
                <label class="fi-fo-field-wrp-label text-sm font-medium text-gray-950 dark:text-white">Category</label>
                <x-filament::input.select wire:model.live="categoryFilter">
                    <option value="">All categories</option>
                    @foreach($this->categoryOptions() as $id => $name)
                        <option value="{{ $id }}">{{ $name }}</option>
                    @endforeach
                </x-filament::input.select>
            </div>
        @endif

        <div class="min-w-[10rem]">
            <label class="fi-fo-field-wrp-label text-sm font-medium text-gray-950 dark:text-white">Priority</label>
            <x-filament::input.select wire:model.live="priorityFilter">
                <option value="">All priorities</option>
                @foreach($this->priorityOptions() as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </x-filament::input.select>
        </div>

        <div class="min-w-[10rem]">
            <label class="fi-fo-field-wrp-label text-sm font-medium text-gray-950 dark:text-white">Status</label>
            <x-filament::input.select wire:model.live="statusFilter">
                <option value="">All statuses</option>
                @foreach($this->statusOptions() as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </x-filament::input.select>
        </div>
    </div>

    @if($this->canManageStaffView() && $viewMode === 'staff' && ! $staffFilter)
        <div class="fi-section rounded-xl bg-white p-6 text-center text-sm text-gray-500 shadow-sm dark:bg-gray-900 dark:text-gray-400">
            Select a staff member above to see their schedule.
        </div>
    @else
        <div
            wire:key="calendar-{{ md5(json_encode([$viewMode, $staffFilter, $categoryFilter, $priorityFilter, $statusFilter])) }}"
            wire:ignore
            x-data="serviceCalendar(@js($this->getCalendarEvents()))"
            x-init="init($el)"
            class="fi-section rounded-xl bg-white p-2 shadow-sm dark:bg-gray-900"
        ></div>
    @endif

    <script>
        function serviceCalendar(events) {
            return {
                calendar: null,
                init(el) {
                    this.calendar = new FullCalendar.Calendar(el, {
                        initialView: 'dayGridMonth',
                        headerToolbar: {
                            left: 'prev,next today',
                            center: 'title',
                            right: 'dayGridMonth,timeGridWeek,timeGridDay',
                        },
                        height: 'auto',
                        events: events,
                        eventClick: (info) => {
                            info.jsEvent.preventDefault();
                            if (info.event.url) {
                                window.location.href = info.event.url;
                            }
                        },
                        eventDidMount: (info) => {
                            const props = info.event.extendedProps;
                            info.el.setAttribute(
                                'title',
                                `${info.event.title}\nStatus: ${props.status}\nPriority: ${props.priority}\nStaff: ${props.staff}`
                            );
                        },
                    });
                    this.calendar.render();
                },
            };
        }
    </script>
</x-filament-panels::page>
