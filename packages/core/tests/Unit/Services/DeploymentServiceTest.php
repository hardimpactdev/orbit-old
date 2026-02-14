<?php

declare(strict_types=1);

use HardImpact\Orbit\Core\Enums\DeploymentStatus;
use HardImpact\Orbit\Core\Enums\NodeEnvironment;
use HardImpact\Orbit\Core\Enums\NodeType;
use HardImpact\Orbit\Core\Models\Deployment;
use HardImpact\Orbit\Core\Models\Node;
use HardImpact\Orbit\Core\Services\CloudflareService;
use HardImpact\Orbit\Core\Services\DeploymentService;
use HardImpact\Orbit\Core\Services\OrbitCli\Shared\CommandService;

beforeEach(function () {
    $this->commandService = mock(CommandService::class);
    $this->cloudflareService = mock(CloudflareService::class);
    $this->service = new DeploymentService($this->commandService, $this->cloudflareService);
});

describe('DeploymentService', function () {
    describe('deploy', function () {
        it('creates deployment and calls CLI on target node', function () {
            $node = Node::factory()->client()->create();

            $this->commandService->shouldReceive('executeCommand')
                ->once()
                ->withArgs(function ($n, $cmd) use ($node) {
                    return $n->id === $node->id
                        && str_contains($cmd, 'site:create my-app')
                        && str_contains($cmd, '--json')
                        && str_contains($cmd, '--clone=org/repo');
                })
                ->andReturn([
                    'success' => true,
                    'data' => [
                        'domain' => 'my-app.ccc',
                        'url' => 'https://my-app.ccc',
                    ],
                ]);

            $deployment = $this->service->deploy($node, [
                'name' => 'my-app',
                'clone' => 'org/repo',
            ]);

            expect($deployment->status)->toBe(DeploymentStatus::Active);
            expect($deployment->project_slug)->toBe('my-app');
            expect($deployment->domain)->toBe('my-app.ccc');
            expect($deployment->node_id)->toBe($node->id);
        });

        it('marks deployment as failed when CLI fails', function () {
            $node = Node::factory()->client()->create();

            $this->commandService->shouldReceive('executeCommand')
                ->once()
                ->andReturn([
                    'success' => false,
                    'error' => 'Clone failed: repository not found',
                ]);

            $deployment = $this->service->deploy($node, ['name' => 'bad-app']);

            expect($deployment->status)->toBe(DeploymentStatus::Failed);
            expect($deployment->error_message)->toBe('Clone failed: repository not found');
        });

        it('throws when active deployment already exists', function () {
            $node = Node::factory()->create();

            Deployment::create([
                'node_id' => $node->id,
                'project_slug' => 'existing',
                'project_name' => 'existing',
                'status' => DeploymentStatus::Active,
            ]);

            expect(fn () => $this->service->deploy($node, ['name' => 'existing']))
                ->toThrow(\RuntimeException::class, "Active deployment for 'existing' already exists");
        });

        it('allows deploy when previous deployment was removed', function () {
            $node = Node::factory()->create();

            Deployment::create([
                'node_id' => $node->id,
                'project_slug' => 'previously-removed',
                'project_name' => 'previously-removed',
                'status' => DeploymentStatus::Removed,
            ]);

            $this->commandService->shouldReceive('executeCommand')
                ->once()
                ->andReturn(['success' => true, 'data' => []]);

            $deployment = $this->service->deploy($node, ['name' => 'previously-removed']);

            expect($deployment->status)->toBe(DeploymentStatus::Active);
        });

        it('passes template and php_version to CLI', function () {
            $node = Node::factory()->create();

            $this->commandService->shouldReceive('executeCommand')
                ->once()
                ->withArgs(function ($n, $cmd) {
                    return str_contains($cmd, '--template=laravel')
                        && str_contains($cmd, '--php=8.5');
                })
                ->andReturn(['success' => true, 'data' => []]);

            $this->service->deploy($node, [
                'name' => 'templated',
                'template' => 'laravel',
                'php_version' => '8.5',
            ]);
        });
    });

    describe('undeploy', function () {
        it('calls CLI delete and marks as removed', function () {
            $node = Node::factory()->create();
            $deployment = Deployment::create([
                'node_id' => $node->id,
                'project_slug' => 'to-remove',
                'project_name' => 'To Remove',
                'status' => DeploymentStatus::Active,
            ]);

            $this->commandService->shouldReceive('executeCommand')
                ->once()
                ->withArgs(fn ($n, $cmd) => str_contains($cmd, 'site:delete to-remove --force --json'))
                ->andReturn(['success' => true]);

            $this->cloudflareService->shouldReceive('isConfigured')->never();

            $result = $this->service->undeploy($deployment);

            expect($result)->toBeTrue();
            expect($deployment->fresh()->status)->toBe(DeploymentStatus::Removed);
        });

        it('cleans up cloudflare record when present', function () {
            $node = Node::factory()->create();
            $deployment = Deployment::create([
                'node_id' => $node->id,
                'project_slug' => 'cf-project',
                'project_name' => 'CF Project',
                'status' => DeploymentStatus::Active,
                'cloudflare_record_id' => 'cf-record-123',
            ]);

            $this->commandService->shouldReceive('executeCommand')
                ->andReturn(['success' => true]);

            $this->cloudflareService->shouldReceive('isConfigured')
                ->once()
                ->andReturn(true);

            $this->cloudflareService->shouldReceive('deleteRecord')
                ->once()
                ->with('cf-record-123');

            $this->service->undeploy($deployment);
        });
    });

    describe('syncNode', function () {
        it('creates deployments from remote project list', function () {
            $node = Node::factory()->create();

            $this->commandService->shouldReceive('executeCommand')
                ->once()
                ->andReturn([
                    'success' => true,
                    'data' => [
                        'sites' => [
                            ['slug' => 'site-a', 'name' => 'Site A', 'domain' => 'site-a.ccc', 'php_version' => '8.5'],
                            ['slug' => 'site-b', 'name' => 'Site B', 'domain' => 'site-b.ccc'],
                        ],
                    ],
                ]);

            $result = $this->service->syncNode($node);

            expect($result['success'])->toBeTrue();
            expect($result['synced'])->toBe(2);
            expect(Deployment::where('node_id', $node->id)->count())->toBe(2);
        });

        it('marks removed deployments that are no longer on the node', function () {
            $node = Node::factory()->create();

            Deployment::create([
                'node_id' => $node->id,
                'project_slug' => 'still-here',
                'project_name' => 'Still Here',
                'status' => DeploymentStatus::Active,
            ]);

            Deployment::create([
                'node_id' => $node->id,
                'project_slug' => 'gone-now',
                'project_name' => 'Gone Now',
                'status' => DeploymentStatus::Active,
            ]);

            $this->commandService->shouldReceive('executeCommand')
                ->once()
                ->andReturn([
                    'success' => true,
                    'data' => [
                        'sites' => [
                            ['slug' => 'still-here', 'name' => 'Still Here'],
                        ],
                    ],
                ]);

            $this->service->syncNode($node);

            expect(Deployment::where('project_slug', 'still-here')->first()->status)
                ->toBe(DeploymentStatus::Active);
            expect(Deployment::where('project_slug', 'gone-now')->first()->status)
                ->toBe(DeploymentStatus::Removed);
        });

        it('returns error when CLI command fails', function () {
            $node = Node::factory()->create();

            $this->commandService->shouldReceive('executeCommand')
                ->once()
                ->andReturn(['success' => false, 'error' => 'Connection refused']);

            $result = $this->service->syncNode($node);

            expect($result['success'])->toBeFalse();
            expect($result['error'])->toBe('Connection refused');
        });
    });

    describe('deploymentsForProject', function () {
        it('returns all deployments for a project slug', function () {
            $node1 = Node::factory()->create();
            $node2 = Node::factory()->create();

            Deployment::create([
                'node_id' => $node1->id,
                'project_slug' => 'my-app',
                'project_name' => 'My App',
                'status' => DeploymentStatus::Active,
            ]);

            Deployment::create([
                'node_id' => $node2->id,
                'project_slug' => 'my-app',
                'project_name' => 'My App',
                'status' => DeploymentStatus::Active,
            ]);

            Deployment::create([
                'node_id' => $node1->id,
                'project_slug' => 'other-app',
                'project_name' => 'Other App',
            ]);

            $deployments = $this->service->deploymentsForProject('my-app');

            expect($deployments)->toHaveCount(2);
            expect($deployments->every(fn ($d) => $d->project_slug === 'my-app'))->toBeTrue();
        });
    });

    describe('nodesByEnvironment', function () {
        it('returns active non-gateway nodes for the given environment', function () {
            Node::factory()->client()->production()->create(['name' => 'Prod 1']);
            Node::factory()->client()->production()->create(['name' => 'Prod 2']);
            Node::factory()->client()->development()->create(['name' => 'Dev 1']);
            Node::factory()->gateway()->production()->create(['name' => 'Gateway']);

            $prodNodes = $this->service->nodesByEnvironment(NodeEnvironment::Production);

            expect($prodNodes)->toHaveCount(2);
            expect($prodNodes->pluck('name')->toArray())->toEqualCanonicalizing(['Prod 1', 'Prod 2']);
        });
    });
});
