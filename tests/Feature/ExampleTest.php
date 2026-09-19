<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * The application boots and its routes are wired.
     *
     * `/` is the dashboard and sits behind auth, so a guest must be redirected
     * to login — the framework's stock "expect 200" assertion was never valid
     * for this app and failed on every run.
     */
    public function test_the_application_boots_and_guards_its_root(): void
    {
        $this->get('/')->assertRedirect(route('login'));
    }

    public function test_the_health_endpoint_responds(): void
    {
        $this->get('/up')->assertOk();
    }
}
