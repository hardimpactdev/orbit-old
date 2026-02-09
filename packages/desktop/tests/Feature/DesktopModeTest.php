<?php

declare(strict_types=1);

namespace Tests\Feature;

use HardImpact\Orbit\Core\Models\Node;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DesktopModeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (! config('orbit.multi_node')) {
            $this->markTestSkipped('Skipping DesktopModeTest in web mode.');
        }

        // Ensure desktop mode
        config(['orbit.multi_node' => true]);
    }

    public function test_projects_page_loads_with_route_parameter(): void
    {
        $node = createNode();

        $response = $this->get("/nodes/{$node->id}/projects");

        $response->assertStatus(200);
    }

    public function test_node_management_accessible(): void
    {
        // Need more than one node to avoid redirect to show page
        createNode(['name' => 'Node 1']);
        createNode(['name' => 'Node 2', 'is_default' => false]);
        $this->get('/nodes')->assertStatus(200);
        $this->get('/nodes/create')->assertStatus(200);
    }

    public function test_all_desktop_features_accessible(): void
    {
        $node = createNode();

        // Mock services to avoid real SSH/Process calls
        $this->mock(\HardImpact\Orbit\Core\Services\OrbitCli\StatusService::class, function ($mock) {
            $mock->shouldReceive('checkInstallation')->andReturn([
                'installed' => true,
                'version' => '0.0.1',
                'path' => '/usr/local/bin/orbit',
            ]);
            $mock->shouldReceive('status')->andReturn([
                'success' => true,
                'data' => [
                    'services' => [],
                    'host_services' => [],
                ],
            ]);
        });

        $this->mock(\HardImpact\Orbit\Core\Services\DoctorService::class, function ($mock) {
            $mock->shouldReceive('runChecks')->andReturn([
                'success' => true,
                'status' => 'healthy',
                'checks' => [],
                'summary' => [],
            ]);
        });

        $this->mock(\HardImpact\Orbit\Core\Services\OrbitCli\ConfigurationService::class, function ($mock) {
            $mock->shouldReceive('getReverbConfig')->andReturn([
                'success' => true,
                'data' => ['enabled' => false],
            ]);
            $mock->shouldReceive('getConfig')->andReturn([
                'success' => true,
                'data' => ['tld' => 'test', 'available_php_versions' => ['8.4', '8.5']],
            ]);
        });

        $this->get("/nodes/{$node->id}/services")->assertStatus(200);
        $this->get("/nodes/{$node->id}/configuration")->assertStatus(200);
        $this->get("/nodes/{$node->id}/workspaces")->assertStatus(200);
        $this->get("/nodes/{$node->id}/doctor")->assertStatus(200);
    }

    public function test_inertia_props_multi_node_true(): void
    {
        $node = createNode();

        $response = $this->get("/nodes/{$node->id}/projects");

        $response->assertInertia(fn ($page) => $page->where('multi_node', true)
            ->has('currentNode')
        );
    }

    public function test_dashboard_shows_node_list(): void
    {
        createNode(['name' => 'Node 1']);
        createNode(['name' => 'Node 2', 'is_default' => false]);
        createNode(['name' => 'Node 3', 'is_default' => false]);

        $response = $this->get('/');

        // Dashboard redirects to default environment or first environment
        $response->assertStatus(302);
    }
}
