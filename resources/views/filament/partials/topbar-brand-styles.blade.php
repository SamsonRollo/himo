<style>
    .fi-topbar {
        padding-inline-start: 0 !important;
    }

    .fi-topbar .fi-topbar-start .fi-logo {
        display: none;
    }

    .fi-body-has-topbar .fi-sidebar {
        background-color: var(--color-white) !important;
    }

    .dark .fi-body-has-topbar .fi-sidebar {
        background-color: var(--gray-900) !important;
    }

    .himo-brand-row {
        display: flex !important;
        flex-direction: row !important;
        flex-wrap: nowrap !important;
        align-items: center !important;
        min-width: 0;
    }

    .himo-brand-row > img {
        flex: 0 1 auto;
        min-width: 0;
    }

    .fi-topbar-collapse-sidebar-btn-ctn,
    .fi-topbar-open-sidebar-btn,
    .fi-topbar-close-sidebar-btn,
    .fi-topbar-end .fi-global-search-ctn {
        display: none !important;
    }

    .himo-navbar-sidebar-toggle {
        flex: none;
        margin-right: 10px !important;
    }

    .himo-user-identity {
        display: none;
    }

    .himo-stat-bottom-border {
        border-bottom-width: 4px;
        border-bottom-style: solid;
    }

    .himo-stat-bottom-border-gray { border-bottom-color: var(--gray-400); }
    .himo-stat-bottom-border-gold { border-bottom-color: #FFC72C; }
    .himo-stat-bottom-border-primary { border-bottom-color: #7B1113; }
    .himo-stat-bottom-border-info { border-bottom-color: #0EA5E9; }
    .himo-stat-bottom-border-warning { border-bottom-color: #F59E0B; }
    .himo-stat-bottom-border-success { border-bottom-color: #014421; }

    .fi-wi-table:has(.himo-dashboard-table-height-marker) .fi-ta-ctn {
        max-height: 27rem;
        overflow: hidden;
    }

    .fi-wi-table:has(.himo-dashboard-table-height-marker) .fi-ta-main {
        display: flex;
        flex: 1 1 auto;
        flex-direction: column;
        min-height: 0;
        max-height: 27rem;
        overflow: hidden;
    }

    .fi-wi-table:has(.himo-dashboard-table-height-marker) .fi-ta-header-ctn {
        flex: none;
    }

    .fi-wi-table:has(.himo-dashboard-table-height-marker) .fi-ta-content-ctn {
        flex: 1 1 auto;
        min-height: 0;
        overflow: auto;
    }

    .fi-wi-table:has(.himo-dashboard-table-height-marker) .fi-ta-table > thead {
        z-index: 9;
        background-color: var(--gray-50) !important;
        opacity: 1;
    }

    .fi-wi-table:has(.himo-dashboard-table-height-marker) .fi-ta-table > thead > tr > th {
        position: sticky;
        top: 0;
        z-index: 9;
        background-color: var(--gray-50) !important;
        background-clip: padding-box;
        opacity: 1;
    }

    .dark .fi-wi-table:has(.himo-dashboard-table-height-marker) .fi-ta-table > thead,
    .dark .fi-wi-table:has(.himo-dashboard-table-height-marker) .fi-ta-table > thead > tr > th {
        background-color: var(--gray-900) !important;
    }

    /* ListServiceRequests adds this wrapper around its existing Livewire tabs.
       On desktop, place that header beside Filament's native search toolbar. */
    @media (min-width: 40rem) {
        .fi-ta-header-ctn:has(.himo-service-request-tabs) {
            display: flex;
            align-items: center;
            border-bottom: 1px solid color-mix(in oklab, var(--gray-200) 100%, transparent);
        }

        .dark .fi-ta-header-ctn:has(.himo-service-request-tabs) {
            border-bottom-color: color-mix(in oklab, var(--color-white) 10%, transparent);
        }

        .fi-ta-header-ctn:has(.himo-service-request-tabs) > .fi-ta-header {
            flex: 1 1 0;
            justify-content: flex-end;
            padding: 0 0 0 1.5rem;
            border-bottom: 0;
        }

        .fi-ta-header-ctn:has(.himo-service-request-tabs) > .fi-ta-header-toolbar {
            flex: 1 1 0;
            border-bottom: 0;
        }
    }

    @media (min-width: 40rem) {
        .himo-user-identity {
            display: flex !important;
            flex-direction: column !important;
            align-items: flex-end !important;
            justify-content: center;
            max-width: 14rem;
            text-align: right;
            line-height: 1.1;
        }

        .himo-user-name,
        .himo-user-role {
            display: block;
            width: 100%;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .himo-user-name {
            color: var(--gray-950);
            font-size: var(--text-base);
            font-weight: var(--font-weight-bold);
        }

        .himo-user-role {
            margin-top: 0.125rem;
            color: var(--gray-400);
            font-size: var(--text-xs);
            font-weight: var(--font-weight-normal);
        }

        .dark .himo-user-name {
            color: var(--color-white);
        }

        .dark .himo-user-role {
            color: var(--gray-400);
        }
    }
</style>
