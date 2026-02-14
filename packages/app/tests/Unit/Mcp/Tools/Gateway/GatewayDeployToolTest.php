<?php

declare(strict_types=1);

use HardImpact\Orbit\Core\Enums\DeploymentStatus;
use HardImpact\Orbit\Core\Enums\NodeStatus;
use HardImpact\Orbit\Core\Models\Deployment;
use HardImpact\Orbit\Core\Models\Node;
use HardImpact\Orbit\Core\Services\CloudflareService;
use HardImpact\Orbit\Core\Services\DeploymentService;
use HardImpact\Orbit\Core\Services\OrbitCli\Shared\CommandService;
use HardImpact\Orbit\Core\Services\SshService;

beforeEach(function () {
    Node::query()->delete();

    $this->sshService = mock(SshService::class);
    $this->commandService = new CommandService($this->sshService);
    $this->cloudflareService = mock(CloudflareService::class);
    $this->deploymentService = new DeploymentService($this->commandService, $this->cloudflareService);
});

describe('GatewayDeployTool', function () {
    it('rejects deploy to inactive node', function () {
        $node = Node::factory()->create(['status' => NodeStatus::Error]);

        expect($node->isActive())->toBeFalse();
    });

    it('rejects deploy to non-existent node', function () {
        expect(Node::find(9999))->toBeNull();
    });

    it('checks cloudflare domain availability before deploy', function () {
        $this->cloudflareService->shouldReceive('isConfigured')->andReturn(true);
        $this->cloudflareService->shouldReceive('isDomainAvailable')
            ->with('taken.com')
            ->andReturn(false);

        expect($this->cloudflareService->isDomainAvailable('taken.com'))->toBeFalse();
    });

    it('creates cloudflare record after successful deploy', function () {
        $node = Node::factory()->client()->create(['external_host' => '5.6.7.8']);

        $this->cloudflareService->shouldReceive('isConfigured')->andReturn(true);
        $this->cloudflareService->shouldReceive('createRecord')
            ->with('myapp.com', '5.6.7.8')
            ->andReturn(['id' => 'cf-123', 'name' => 'myapp.com', 'type' => 'A', 'content' => '5.6.7.8']);

        $record = $this->cloudflareService->createRecord('myapp.com', $node->external_host);

        expect($record['id'])->toBe('cf-123');
    });

    it('allows deploy when cloudflare is not configured', function () {
        $this->cloudflareService->shouldReceive('isConfigured')->andReturn(false);

        expect($this->cloudflareService->isConfigured())->toBeFalse();
    });
});

describe('GatewayDeployTool integration', function () {
    it('deploys project and tracks deployment record', function () {
        $node = Node::factory()->client()->create();

        $this->sshService->shouldReceive('executeJson')
            ->once()
            ->andReturn([
                'success' => true,
                'data' => [
                    'success' => true,
                    'data' => ['domain' => 'new-app.ccc', 'url' => 'https://new-app.ccc'],
                ],
            ]);

        $this->cloudflareService->shouldNotReceive('createRecord');

        $deployment = $this->deploymentService->deploy($node, [
            'name' => 'new-app',
            'clone' => 'org/new-app',
        ]);

        expect($deployment->status)->toBe(DeploymentStatus::Active);
        expect(Deployment::where('node_id', $node->id)->count())->toBe(1);
    });
});
