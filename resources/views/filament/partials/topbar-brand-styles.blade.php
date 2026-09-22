<style>
    .fi-topbar {
        padding-inline-start: 0 !important;
    }

    .fi-topbar .fi-topbar-start .fi-logo {
        display: none;
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
    .fi-topbar-end .fi-global-search-ctn {
        display: none !important;
    }

    .himo-sidebar-toggle {
        display: flex;
        align-items: center;
        justify-content: center;
        width: 100%;
        min-height: 2.75rem;
        color: var(--gray-600);
    }

    .himo-sidebar-toggle:hover {
        color: var(--gray-950);
    }

    .dark .himo-sidebar-toggle {
        color: var(--gray-400);
    }

    .dark .himo-sidebar-toggle:hover {
        color: var(--color-white);
    }

    .himo-user-identity {
        display: none;
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
