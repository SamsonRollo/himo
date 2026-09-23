<?php

namespace Tests\Feature;

use Tests\TestCase;

class BrandingTest extends TestCase
{
    public function test_login_page_places_the_two_logo_images_before_the_sign_in_heading(): void
    {
        $response = $this->get('/login')
            ->assertOk()
            ->assertSeeInOrder([
                'alt="University of the Philippines seal"',
                'alt="HIMO — Helpdesk Intake, Management, and Job Orders"',
                'Sign in',
            ], false);
        $response->assertSee('Log in');
        $this->assertSame(1, substr_count($response->getContent(), 'Sign in'));
        $response->assertSee('height: 2rem;', false)
            ->assertSee('height: 1.25rem;', false);
    }

    public function test_authenticated_branding_uses_a_horizontal_logo_row_with_a_fifteen_pixel_up_logo_margin(): void
    {
        $this->view('filament.partials.brand-logo')
            ->assertSeeInOrder([
                'alt="University of the Philippines seal"',
                'alt="HIMO — Helpdesk Intake, Management, and Job Orders"',
            ], false)
            ->assertSee('margin-left: 15px;', false)
            ->assertSee('class="himo-brand-row"', false)
            ->assertSee('height: 100%; width: auto; object-fit: contain;', false);

        $this->view('filament.partials.topbar-brand-logo')
            ->assertSeeInOrder([
                'alt="University of the Philippines seal"',
                'alt="HIMO — Helpdesk Intake, Management, and Job Orders"',
            ], false)
            ->assertSee('height: calc(var(--topbar-height) - 10px);', false)
            ->assertSee('margin: 0 0 0 15px;', false)
            ->assertSee('margin: 0 5px 0 0;', false)
            ->assertSee('height: 100%; width: auto; object-fit: contain;', false);

        $this->view('filament.partials.topbar-brand-styles')
            ->assertSee('padding-inline-start: 0 !important;', false)
            ->assertSee('flex-direction: row !important;', false)
            ->assertSee('flex-wrap: nowrap !important;', false)
            ->assertSee('.fi-topbar-collapse-sidebar-btn-ctn,', false)
            ->assertSee('.fi-topbar-end .fi-global-search-ctn', false);
    }

    public function test_topbar_toggle_reuses_filaments_sidebar_store_with_a_menu_icon(): void
    {
        $this->view('filament.partials.topbar-brand-logo')
            ->assertSee('Toggle sidebar', false)
            ->assertSee('$store.sidebar.isOpen ? $store.sidebar.close() : $store.sidebar.open()', false);
    }
}
