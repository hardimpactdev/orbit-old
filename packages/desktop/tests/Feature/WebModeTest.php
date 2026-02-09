<?php

declare(strict_types=1);

namespace Tests\Feature;

use HardImpact\Orbit\Core\Models\Node;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebModeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (config('orbit.multi_node')) {
            $this->markTestSkipped('Skipping WebModeTest in desktop mode.');
        }

        // Create local node
        createNode([
            'is_default' => true,
            'name' => 'Local',
            'host' => 'localhost',
        ]);
    }

    public function test_dashboard_redirects_to_projects(): void
    {
        $response = $this->get('/');

        $response->assertRedirect('/projects');
    }

    public function test_projects_page_loads_with_implicit_node(): void
    {
        $response = $this->get('/projects');

        $response->assertStatus(200);
    }

    public function test_services_page_loads_with_implicit_node(): void
    {
        $response = $this->get('/services');

        $response->assertStatus(200);
    }

    public function test_desktop_only_routes_return_403(): void
    {
        $this->get('/nodes')->assertStatus(403);
        $this->get('/nodes/create')->assertStatus(403);
        $this->get('/ssh-keys/available')->assertStatus(403);
    }

    public function test_inertia_props_include_current_node(): void
    {
        $response = $this->get('/projects');

        $response->assertInertia(fn ($page) => $page->has('currentNode')
            ->where('multi_node', false)
        );
    }
}
