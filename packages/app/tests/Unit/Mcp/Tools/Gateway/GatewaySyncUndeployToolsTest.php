<?php

declare(strict_types=1);

use HardImpact\Orbit\Core\Enums\DeploymentStatus;
use HardImpact\Orbit\Core\Models\Deployment;
use HardImpact\Orbit\Core\Models\Node;
use HardImpact\Orbit\Core\Services\CloudflareService;
use HardImpact\Orbit\Core\Services\DeploymentService;
use HardImpact\Orbit\Core\Services\OrbitCli\Shared\CommandService;
use HardImpact\Orbit\Core\Services\RemoteDeploy\RemoteDeploymentOrchestrator;
use HardImpact\Orbit\Core\Services\SshService;

beforeEach(function () {
    Node::query()->delete();

    $this->sshService = mock(SshService::class);
    $this->commandService = new CommandService($this->sshService);
    $this->cloudflareService = mock(CloudflareService::class);
    $this->orchestrator = mock(RemoteDeploymentOrchestrator::class);
    $this->deploymentService = new DeploymentService($this->commandService, $this->cloudflareService, $this->orchestrator);
});

describe('GatewaySyncNodeTool', function () {
    it('syncs projects from a remote node', function () {
        $node = Node::factory()->client()->create();

        $this->sshService->shouldReceive('executeJson')
            ->once()
            ->andReturn([
                'success' => true,
                'data' => [
                    'success' => true,
                    'data' => [
                        'sites' => [
                            ['slug' => 'existing-app', 'name' => 'Existing App', 'domain' => 'existing.ccc'],
                        ],
                    ],
                ],
            ]);

        $result = $this->deploymentService->syncNode($node);

        expect($result['success'])->toBeTrue();
        expect($result['synced'])->toBe(1);

        $deployment = Deployment::where('node_id', $node->id)->first();
        expect($deployment->project_slug)->toBe('existing-app');
        expect($deployment->status)->toBe(DeploymentStatus::Active);
    });

    it('upserts existing deployments on re-sync', function () {
        $node = Node::factory()->client()->create();

        Deployment::create([
            'node_id' => $node->id,
            'project_slug' => 'app',
            'project_name' => 'Old Name',
            'domain' => 'old.ccc',
            'status' => DeploymentStatus::Active,
        ]);

        $this->sshService->shouldReceive('executeJson')
            ->once()
            ->andReturn([
                'success' => true,
                'data' => [
                    'success' => true,
                    'data' => [
                        'sites' => [
                            ['slug' => 'app', 'name' => 'New Name', 'domain' => 'new.ccc'],
                        ],
                    ],
                ],
            ]);

        $this->deploymentService->syncNode($node);

        $deployment = Deployment::where('node_id', $node->id)->where('project_slug', 'app')->first();
        expect($deployment->project_name)->toBe('New Name');
        expect($deployment->domain)->toBe('new.ccc');
        expect(Deployment::where('node_id', $node->id)->count())->toBe(1);
    });
});

describe('GatewayDeployTool preflight', function () {
    it('detects SSH connectivity failure', function () {
        $node = Node::factory()->client()->create();

        $this->sshService->shouldReceive('testConnection')
            ->once()
            ->with($node)
            ->andReturn(['success' => false, 'message' => 'Connection timed out']);

        // Use reflection to test the private preflight method
        $tool = new \HardImpact\Orbit\App\Mcp\Tools\Gateway\GatewayDeployTool(
            $this->deploymentService,
            $this->cloudflareService,
        );

        app()->instance(SshService::class, $this->sshService);
        app()->instance(CommandService::class, $this->commandService);

        $method = new \ReflectionMethod($tool, 'preflight');
        $result = $method->invoke($tool, $node, null);

        expect($result)->not->toBeNull();
    });

    it('detects missing CLI binary', function () {
        $node = Node::factory()->client()->create();

        $this->sshService->shouldReceive('testConnection')
            ->once()
            ->andReturn(['success' => true, 'message' => 'Connected']);

        $this->sshService->shouldReceive('execute')
            ->once()
            ->andReturn(['success' => false, 'output' => '', 'error' => '']);

        $tool = new \HardImpact\Orbit\App\Mcp\Tools\Gateway\GatewayDeployTool(
            $this->deploymentService,
            $this->cloudflareService,
        );

        app()->instance(SshService::class, $this->sshService);
        app()->instance(CommandService::class, $this->commandService);

        $method = new \ReflectionMethod($tool, 'preflight');
        $result = $method->invoke($tool, $node, null);

        expect($result)->not->toBeNull();
    });

    it('detects repo access failure', function () {
        $node = Node::factory()->client()->create();

        $this->sshService->shouldReceive('testConnection')
            ->once()
            ->andReturn(['success' => true, 'message' => 'Connected']);

        // findBinary call
        $this->sshService->shouldReceive('execute')
            ->once()
            ->withArgs(fn ($n, $cmd) => str_contains($cmd, 'for path in'))
            ->andReturn(['success' => true, 'output' => '/usr/local/bin/orbit']);

        // gh repo view call
        $this->sshService->shouldReceive('execute')
            ->once()
            ->withArgs(fn ($n, $cmd) => str_contains($cmd, 'gh repo view'))
            ->andReturn(['success' => false, 'output' => '', 'error' => 'To get started with GitHub CLI, please run: gh auth login']);

        $tool = new \HardImpact\Orbit\App\Mcp\Tools\Gateway\GatewayDeployTool(
            $this->deploymentService,
            $this->cloudflareService,
        );

        app()->instance(SshService::class, $this->sshService);
        app()->instance(CommandService::class, $this->commandService);

        $method = new \ReflectionMethod($tool, 'preflight');
        $result = $method->invoke($tool, $node, 'org/private-repo');

        expect($result)->not->toBeNull();
    });

    it('passes when all checks succeed', function () {
        $node = Node::factory()->client()->create();

        $this->sshService->shouldReceive('testConnection')
            ->once()
            ->andReturn(['success' => true, 'message' => 'Connected']);

        // findBinary
        $this->sshService->shouldReceive('execute')
            ->once()
            ->withArgs(fn ($n, $cmd) => str_contains($cmd, 'for path in'))
            ->andReturn(['success' => true, 'output' => '/usr/local/bin/orbit']);

        // gh repo view
        $this->sshService->shouldReceive('execute')
            ->once()
            ->withArgs(fn ($n, $cmd) => str_contains($cmd, 'gh repo view'))
            ->andReturn(['success' => true, 'output' => '{"name":"repo"}', 'error' => '']);

        $tool = new \HardImpact\Orbit\App\Mcp\Tools\Gateway\GatewayDeployTool(
            $this->deploymentService,
            $this->cloudflareService,
        );

        app()->instance(SshService::class, $this->sshService);
        app()->instance(CommandService::class, $this->commandService);

        $method = new \ReflectionMethod($tool, 'preflight');
        $result = $method->invoke($tool, $node, 'org/repo');

        expect($result)->toBeNull();
    });
});

describe('GatewayUndeployTool', function () {
    it('removes deployment and marks as removed', function () {
        $node = Node::factory()->client()->create();

        $deployment = Deployment::create([
            'node_id' => $node->id,
            'project_slug' => 'to-undeploy',
            'project_name' => 'To Undeploy',
            'status' => DeploymentStatus::Active,
        ]);

        $this->sshService->shouldReceive('executeJson')
            ->once()
            ->andReturn([
                'success' => true,
                'data' => ['success' => true],
            ]);

        $this->cloudflareService->shouldNotReceive('deleteRecord');

        $result = $this->deploymentService->undeploy($deployment);

        expect($result)->toBeTrue();
        expect($deployment->fresh()->status)->toBe(DeploymentStatus::Removed);
    });

    it('cleans up cloudflare when record exists', function () {
        $node = Node::factory()->client()->create();

        $deployment = Deployment::create([
            'node_id' => $node->id,
            'project_slug' => 'cf-deploy',
            'project_name' => 'CF Deploy',
            'status' => DeploymentStatus::Active,
            'cloudflare_record_id' => 'cf-rec-456',
        ]);

        $this->sshService->shouldReceive('executeJson')
            ->once()
            ->andReturn(['success' => true, 'data' => ['success' => true]]);

        $this->cloudflareService->shouldReceive('isConfigured')->once()->andReturn(true);
        $this->cloudflareService->shouldReceive('deleteRecord')->once()->with('cf-rec-456', null);
        $this->cloudflareService->shouldReceive('purgeCache')->once()->with(null);

        $this->deploymentService->undeploy($deployment);

        expect($deployment->fresh()->status)->toBe(DeploymentStatus::Removed);
    });
});
