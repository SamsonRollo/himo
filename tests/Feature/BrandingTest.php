<?php

namespace Tests\Feature;

use Tests\TestCase;

class BrandingTest extends TestCase
{
    public function test_login_page_places_the_two_logo_images_before_the_sign_in_heading(): void
    {
        $response = $this->get('/admin/login')
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

    public function test_authenticated_brand_partials_preserve_the_exact_topbar_spacing(): void
    {
        $this->view('filament.partials.topbar-brand-logo')
            ->assertSeeInOrder([
                'alt="University of the Philippines seal"',
                'alt="HIMO — Helpdesk Intake, Management, and Job Orders"',
            ], false)
            ->assertSee('height: calc(var(--topbar-height) - 10px);', false)
            ->assertSee('gap: 5px;', false)
            ->assertSee('height: 100%; width: auto; object-fit: contain;', false);

        $this->view('filament.partials.topbar-brand-styles')
            ->assertSee('padding-inline-start: 5px !important;', false)
            ->assertSee('display: none;', false);
    }
}
