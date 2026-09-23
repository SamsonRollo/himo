<x-filament-panels::page>
    <style>
        .himo-service-calendar {
            --calendar-border: var(--gray-200);
            --calendar-muted: var(--gray-500);
            --calendar-surface: var(--color-white);
        }

        .dark .himo-service-calendar {
            --calendar-border: color-mix(in oklab, var(--color-white) 12%, transparent);
            --calendar-muted: var(--gray-400);
            --calendar-surface: var(--gray-900);
        }

        .himo-calendar-controls {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(10rem, 1fr));
        }

        .himo-calendar-controls > div {
            min-width: 0;
        }

        .himo-calendar-controls select {
            width: 100%;
        }

        .himo-calendar-scroll {
            overflow-x: auto;
            scrollbar-color: var(--calendar-muted) transparent;
            scrollbar-width: thin;
        }

        .himo-calendar-root {
            min-width: 42rem;
        }

        .himo-calendar-root .fc {
            color: var(--gray-950);
            font-size: 0.875rem;
        }

        .dark .himo-calendar-root .fc {
            color: var(--color-white);
        }

        .himo-calendar-root .fc .fc-toolbar {
            align-items: center;
            gap: 0.75rem;
            margin-bottom: 1.25rem;
        }

        .himo-calendar-root .fc .fc-toolbar-title {
            color: inherit;
            font-size: 1.25rem;
            font-weight: 700;
            letter-spacing: -0.025em;
        }

        .himo-calendar-root .fc .fc-button {
            border: 1px solid var(--calendar-border);
            border-radius: 0.5rem;
            background: var(--calendar-surface);
            box-shadow: none;
            color: inherit;
            font-size: 0.8125rem;
            font-weight: 600;
            padding: 0.45rem 0.7rem;
            text-transform: none;
        }

        .himo-calendar-root .fc .fc-button:hover,
        .himo-calendar-root .fc .fc-button:focus {
            background: var(--gray-100);
            color: var(--gray-950);
        }

        .dark .himo-calendar-root .fc .fc-button:hover,
        .dark .himo-calendar-root .fc .fc-button:focus {
            background: var(--gray-800);
            color: var(--color-white);
        }

        .himo-calendar-root .fc .fc-button-active,
        .himo-calendar-root .fc .fc-button-active:hover {
            border-color: #7B1113;
            background: #7B1113;
            color: var(--color-white);
        }

        .himo-calendar-root .fc-theme-standard td,
        .himo-calendar-root .fc-theme-standard th,
        .himo-calendar-root .fc-theme-standard .fc-scrollgrid {
            border-color: var(--calendar-border);
        }

        .himo-calendar-root .fc .fc-col-header-cell {
            background: color-mix(in oklab, var(--gray-100) 70%, transparent);
            padding: 0.625rem 0.25rem;
        }

        .dark .himo-calendar-root .fc .fc-col-header-cell {
            background: color-mix(in oklab, var(--gray-800) 70%, transparent);
        }

        .himo-calendar-root .fc .fc-col-header-cell-cushion {
            color: var(--calendar-muted);
            font-size: 0.75rem;
            font-weight: 700;
            letter-spacing: 0.025em;
            text-decoration: none;
            text-transform: uppercase;
        }

        .himo-calendar-root .fc .fc-daygrid-day-number {
            color: inherit;
            font-size: 0.8125rem;
            font-weight: 600;
            padding: 0.5rem;
            text-decoration: none;
        }

        .himo-calendar-root .fc .fc-day-today {
            background: color-mix(in oklab, #FFC72C 16%, transparent);
        }

        .dark .himo-calendar-root .fc .fc-day-today {
            background: color-mix(in oklab, #FFC72C 12%, transparent);
        }

        .himo-calendar-root .fc .fc-event {
            border: 0;
            border-radius: 0.375rem;
            box-shadow: 0 1px 2px rgb(15 23 42 / 0.16);
            font-size: 0.75rem;
            font-weight: 600;
            line-height: 1.3;
            margin: 0.125rem 0.25rem;
            padding: 0.2rem 0.35rem;
        }

        .himo-calendar-root .fc .fc-event:hover {
            filter: brightness(0.93);
        }

        @media (max-width: 639px) {
            .himo-calendar-root .fc .fc-toolbar {
                align-items: stretch;
                flex-direction: column;
            }

            .himo-calendar-root .fc .fc-toolbar-chunk {
                display: flex;
                justify-content: center;
            }

            .himo-calendar-root .fc .fc-toolbar-title {
                text-align: center;
            }
        }
    </style>

    <div class="himo-service-calendar space-y-5">
        <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm sm:p-5 dark:border-white/10 dark:bg-gray-900">
            <div class="himo-calendar-controls gap-4">
                @if($this->canManageStaffView())
                    <div>
                        <label class="fi-fo-field-wrp-label text-sm font-medium text-gray-950 dark:text-white">View</label>
                        <x-filament::input.select wire:model.live="viewMode">
                            <option value="services">Services view</option>
                            <option value="staff">Service Staff view</option>
                        </x-filament::input.select>
                    </div>

                    @if($viewMode === 'staff')
                        <div>
                            <label class="fi-fo-field-wrp-label text-sm font-medium text-gray-950 dark:text-white">Staff member</label>
                            <x-filament::input.select wire:model.live="staffFilter">
                                <option value="">Select a staff member…</option>
                                @foreach($this->staffOptions() as $id => $name)
                                    <option value="{{ $id }}">{{ $name }}</option>
                                @endforeach
                            </x-filament::input.select>
                        </div>
                    @else
                        <div>
                            <label class="fi-fo-field-wrp-label text-sm font-medium text-gray-950 dark:text-white">Staff</label>
                            <x-filament::input.select wire:model.live="staffFilter">
                                <option value="">All staff</option>
                                @foreach($this->staffOptions() as $id => $name)
                                    <option value="{{ $id }}">{{ $name }}</option>
                                @endforeach
                            </x-filament::input.select>
                        </div>
                    @endif

                    <div>
                        <label class="fi-fo-field-wrp-label text-sm font-medium text-gray-950 dark:text-white">Category</label>
                        <x-filament::input.select wire:model.live="categoryFilter">
                            <option value="">All categories</option>
                            @foreach($this->categoryOptions() as $id => $name)
                                <option value="{{ $id }}">{{ $name }}</option>
                            @endforeach
                        </x-filament::input.select>
                    </div>
                @endif

                <div>
                    <label class="fi-fo-field-wrp-label text-sm font-medium text-gray-950 dark:text-white">Priority</label>
                    <x-filament::input.select wire:model.live="priorityFilter">
                        <option value="">All priorities</option>
                        @foreach($this->priorityOptions() as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </x-filament::input.select>
                </div>

                <div>
                    <label class="fi-fo-field-wrp-label text-sm font-medium text-gray-950 dark:text-white">Status</label>
                    <x-filament::input.select wire:model.live="statusFilter">
                        <option value="">All statuses</option>
                        @foreach($this->statusOptions() as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </x-filament::input.select>
                </div>
            </div>
        </div>

        @if($this->canManageStaffView() && $viewMode === 'staff' && ! $staffFilter)
            <div class="rounded-xl border border-dashed border-gray-300 bg-white px-6 py-12 text-center text-sm text-gray-500 shadow-sm dark:border-white/15 dark:bg-gray-900 dark:text-gray-400">
                Select a staff member above to see their schedule.
            </div>
        @else
            <div class="rounded-xl border border-gray-200 bg-white p-3 shadow-sm sm:p-5 dark:border-white/10 dark:bg-gray-900">
                <div class="himo-calendar-scroll">
                    <div
                        wire:key="calendar-{{ md5(json_encode([$viewMode, $staffFilter, $categoryFilter, $priorityFilter, $statusFilter])) }}"
                        wire:ignore
                        x-data="serviceCalendar(@js($this->getCalendarEvents()))"
                        x-init="init($el)"
                        class="himo-calendar-root"
                    ></div>
                </div>
            </div>
        @endif
    </div>

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
