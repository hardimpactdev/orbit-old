<?php

namespace Tests\Feature;

use HardImpact\Orbit\Core\Models\Node;
use HardImpact\Orbit\Core\Models\SshKey;
use HardImpact\Orbit\Core\Services\OrbitCli\ServiceControlService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class ServiceControlTest extends TestCase
{
    use RefreshDatabase;

    protected Node $node;

    protected function setUp(): void
    {
        parent::setUp();

        // Create a default SSH key
        SshKey::create([
            'name' => 'Default Key',
            'public_key' => 'ssh-rsa AAA...',
            'private_key' => '---BEGIN...',
            'is_default' => true,
        ]);

        // Create a node
        $this->node = Node::create([
            'name' => 'Test Server',
            'host' => '1.2.3.4',
            'user' => 'orbit',
            'port' => 22,
            'status' => 'active',
            'is_default' => true,
        ]);
    }

    public function test_can_start_host_service(): void
    {
        $this->mock(ServiceControlService::class, function (MockInterface $mock) {
            $mock->shouldReceive('startHostService')
                ->with(\Mockery::on(fn ($e) => $e->id === $this->node->id), 'caddy')
                ->once()
                ->andReturn(['success' => true]);
        });

        $response = $this->post(route('nodes.host-services.start', [
            'node' => $this->node,
            'service' => 'caddy',
        ]));

        $response->assertStatus(200)
            ->assertJson(['success' => true]);
    }

    public function test_can_stop_host_service(): void
    {
        $this->mock(ServiceControlService::class, function (MockInterface $mock) {
            $mock->shouldReceive('stopHostService')
                ->with(\Mockery::on(fn ($e) => $e->id === $this->node->id), 'php-8.4')
                ->once()
                ->andReturn(['success' => true]);
        });

        $response = $this->post(route('nodes.host-services.stop', [
            'node' => $this->node,
            'service' => 'php-8.4',
        ]));

        $response->assertStatus(200)
            ->assertJson(['success' => true]);
    }

    public function test_can_restart_host_service(): void
    {
        $this->mock(ServiceControlService::class, function (MockInterface $mock) {
            $mock->shouldReceive('restartHostService')
                ->with(\Mockery::on(fn ($e) => $e->id === $this->node->id), 'horizon')
                ->once()
                ->andReturn(['success' => true]);
        });

        $response = $this->post(route('nodes.host-services.restart', [
            'node' => $this->node,
            'service' => 'horizon',
        ]));

        $response->assertStatus(200)
            ->assertJson(['success' => true]);
    }

    public function test_can_disable_service(): void
    {
        $this->mock(ServiceControlService::class, function (MockInterface $mock) {
            $mock->shouldReceive('disable')
                ->with(\Mockery::on(fn ($e) => $e->id === $this->node->id), 'mysql')
                ->once()
                ->andReturn(['success' => true]);
        });

        $response = $this->delete(route('nodes.services.disable', [
            'node' => $this->node,
            'service' => 'mysql',
        ]));

        $response->assertStatus(200)
            ->assertJson(['success' => true]);
    }

    public function test_dynamic_php_versions_in_config(): void
    {
        // Mock getConfig to return available_php_versions
        $this->mock(\HardImpact\Orbit\Core\Services\OrbitCli\ConfigurationService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getConfig')
                ->once()
                ->andReturn([
                    'success' => true,
                    'data' => [
                        'available_php_versions' => ['8.3', '8.4', '8.5', '8.6'],
                        'tld' => 'test',
                    ],
                ]);
        });

        $response = $this->get("/api/nodes/{$this->node->id}/config");

        $response->assertStatus(200)
            ->assertJsonPath('data.available_php_versions', ['8.3', '8.4', '8.5', '8.6']);
    }
}
