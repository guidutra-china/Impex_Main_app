<?php

namespace Tests\Feature\TripsMobile;

use Tests\TestCase;

class TripsMobileShellTest extends TestCase
{
    public function test_spa_shell_renders(): void
    {
        $this->get('/trips-mobile')
            ->assertOk()
            ->assertSee('Impex Viagens')
            ->assertSee('tripsApp', false);
    }

    public function test_spa_catch_all_renders(): void
    {
        $this->get('/trips-mobile/anything/here')
            ->assertOk()
            ->assertSee('Impex Viagens');
    }

    public function test_manifest_is_served(): void
    {
        $response = $this->get('/trips-mobile/manifest.json');
        $response->assertOk();
        $this->assertStringContainsString('application/manifest+json', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('Impex Viagens', file_get_contents($response->getFile()->getPathname()));
    }

    public function test_service_worker_is_served_with_scope_header(): void
    {
        $response = $this->get('/trips-mobile/sw.js');
        $response->assertOk();
        $this->assertSame('/trips-mobile/', $response->headers->get('Service-Worker-Allowed'));
        $this->assertStringContainsString('trips-mobile-sync', file_get_contents($response->getFile()->getPathname()));
    }
}
