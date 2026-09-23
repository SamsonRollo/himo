<x-filament-panels::page.simple :heading="''" :subheading="''">
<style>
    .himo-login-brand {
        display: flex;
        flex-direction: row;
        flex-wrap: nowrap;
        align-items: center;
        justify-content: center;
        gap: 0.75rem;
        width: 100%;
        margin-bottom: 1rem;
    }

    .himo-login-brand-up,
    .himo-login-brand-himo {
        display: block;
        flex: none;
        width: auto;
        object-fit: contain;
    }

    .himo-login-brand-up {
        height: 4rem;
    }

    .himo-login-brand-himo {
        height: 2.5rem;
    }

    @media (min-width: 640px) {
        .himo-login-brand {
            margin-bottom: 1.25rem;
        }

        .himo-login-brand-up {
            height: 5rem;
        }

        .himo-login-brand-himo {
            height: 3rem;
        }
    }
</style>

    <header class="fi-simple-header">
        <div class="himo-login-brand">
            <img
                src="{{ asset('images/branding/up-logo.png') }}"
                alt="University of the Philippines seal"
                class="himo-login-brand-up"
            />
            <img
                src="{{ asset('images/branding/himo-logo.png') }}"
                alt="HIMO — Helpdesk Intake, Management, and Job Orders"
                class="himo-login-brand-himo"
            />
        </div>

        <h1 class="fi-simple-header-heading">
            {{ $this->getHeading() }}
        </h1>

        @if (filled($this->getSubheading()))
            <p class="fi-simple-header-subheading">
                {{ $this->getSubheading() }}
            </p>
        @endif
    </header>

    {{ $this->content }}
</x-filament-panels::page.simple>
