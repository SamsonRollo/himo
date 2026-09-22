<style>
    .himo-login-brand {
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 0.5rem;
        margin-bottom: 1rem;
    }

    .himo-login-brand-up {
        width: auto;
        height: 2rem;
        max-width: 4rem;
        object-fit: contain;
    }

    .himo-login-brand-himo {
        width: auto;
        height: 1.25rem;
        max-width: 7rem;
        object-fit: contain;
    }

    @media (min-width: 640px) {
        .himo-login-brand {
            gap: 0.75rem;
            margin-bottom: 1.25rem;
        }

        .himo-login-brand-up {
            height: 2.5rem;
            max-width: 5rem;
        }

        .himo-login-brand-himo {
            height: 1.5rem;
            max-width: 8rem;
        }
    }
</style>

<div class="fi-simple-page">
    <div class="fi-simple-page-content">
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
    </div>
</div>
