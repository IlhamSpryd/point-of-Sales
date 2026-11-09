<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use Tests\TestCase;

class LoginViewportNoScrollTest extends TestCase
{
    public function test_login_page_renders_with_strict_viewport_lock_and_no_scroll(): void
    {
        $response = $this->get(route('login'));

        $response->assertOk();

        // 1. Verify viewport locking styles in HTML/CSS
        $response->assertSee('overflow: hidden;', false);
        $response->assertSee('overscroll-behavior: none;', false);
        $response->assertSee('max-h-screen', false);
        $response->assertSee('h-[100dvh]', false);

        // 2. Verify Body & Swup container classes prevent scrolling
        $response->assertSee('overflow-hidden', false);
        $response->assertDontSee('min-h-screen overflow-x-hidden', false);

        // 3. Verify Form elements are present
        $response->assertSee('Sign in to Apeiron');
        $response->assertSee('id="email"', false);
        $response->assertSee('id="password"', false);
        $response->assertSee('id="btn-masuk"', false);
        $response->assertSee('Google');
        $response->assertSee('Apple');
    }
}

