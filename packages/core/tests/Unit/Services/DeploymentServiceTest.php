<?php

declare(strict_types=1);

use HardImpact\Orbit\Core\Enums\DeploymentStatus;
use HardImpact\Orbit\Core\Enums\NodeEnvironment;
use HardImpact\Orbit\Core\Models\Deployment;
use HardImpact\Orbit\Core\Models\GatewayProject;
use HardImpact\Orbit\Core\Models\Node;
use HardImpact\Orbit\Core\Services\CloudflareService;
use HardImpact\Orbit\Core\Services\DeploymentService;
use HardImpact\Orbit\Core\Services\OrbitCli\Shared\CommandService;
use HardImpact\Orbit\Core\Services\RemoteDeploy\RemoteDeploymentOrchestrator;

beforeEach(function () {
    $this->commandService = mock(CommandService::class);
    $this->cloudflareService = mock(CloudflareService::class);
    $this->orchestrator = mock(RemoteDeploymentOrchestrator::class);
    $this->service = new DeploymentService($this->commandService, $this->cloudflareService, $this->orchestrator);
});

describe('DeploymentService', function () {
    describe('deploy', function () {
        it('creates deployment and calls CLI on target node', function () {
            $node = Node::factory()->client()->create();

            $this->commandService->shouldReceive('executeCommand')
                ->once()
                ->withArgs(function ($n, $cmd) use ($node) {
                    return $n->id === $node->id
                        && str_contains($cmd, 'project:create')
                        && str_contains($cmd, 'my-app')
                        && str_contains($cmd, '--json')
                        && str_contains($cmd, '--clone=');
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

        it('stores meaningful error when CLI returns empty error string', function () {
            $node = Node::factory()->client()->create();

            $this->commandService->shouldReceive('executeCommand')
                ->once()
                ->andReturn([
                    'success' => false,
                    'error' => '',
                ]);

            $deployment = $this->service->deploy($node, ['name' => 'empty-err']);

            expect($deployment->status)->toBe(DeploymentStatus::Failed);
            expect($deployment->error_message)->not->toBeEmpty();
            expect($deployment->error_message)->toBe('CLI deployment command failed — check node connectivity and CLI installation');
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
                    return str_contains($cmd, '--template=')
                        && str_contains($cmd, 'laravel')
                        && str_contains($cmd, '--php=')
                        && str_contains($cmd, '8.5');
                })
                ->andReturn(['success' => true, 'data' => []]);

            $this->service->deploy($node, [
                'name' => 'templated',
                'template' => 'laravel',
                'php_version' => '8.5',
            ]);
        });

        it('uses remote orchestrator for production nodes', function () {
            $node = Node::factory()->client()->production()->create();

            $this->orchestrator->shouldReceive('detectPhpVersion')
                ->once()
                ->andReturn('8.5');

            $this->orchestrator->shouldReceive('deploy')
                ->once()
                ->andReturn(['success' => true, 'domain' => 'prod-app.bear', 'url' => 'https://prod-app.bear']);

            $this->commandService->shouldNotReceive('executeCommand');

            $deployment = $this->service->deploy($node, ['name' => 'prod-app', 'clone' => 'org/repo']);

            expect($deployment->status)->toBe(DeploymentStatus::Active);
        });

        it('uses remote orchestrator for staging nodes', function () {
            $node = Node::factory()->client()->staging()->create();

            $this->orchestrator->shouldReceive('detectPhpVersion')
                ->once()
                ->andReturn('8.5');

            $this->orchestrator->shouldReceive('deploy')
                ->once()
                ->andReturn(['success' => true, 'domain' => 'staging-app.bear', 'url' => 'https://staging-app.bear']);

            $this->commandService->shouldNotReceive('executeCommand');

            $deployment = $this->service->deploy($node, ['name' => 'staging-app', 'clone' => 'org/repo']);

            expect($deployment->status)->toBe(DeploymentStatus::Active);
        });

        it('uses CLI for development nodes', function () {
            $node = Node::factory()->client()->development()->create();

            $this->commandService->shouldReceive('executeCommand')
                ->once()
                ->withArgs(fn ($n, $cmd) => str_contains($cmd, 'project:create'))
                ->andReturn(['success' => true, 'data' => []]);

            $this->orchestrator->shouldNotReceive('deploy');

            $this->service->deploy($node, ['name' => 'dev-app']);
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
                ->withArgs(fn ($n, $cmd) => str_contains($cmd, 'project:delete') && str_contains($cmd, 'to-remove') && str_contains($cmd, '--force --json'))
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
                ->with(null)
                ->andReturn(true);

            $this->cloudflareService->shouldReceive('deleteRecord')
                ->once()
                ->with('cf-record-123', null);

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
                        'projects' => [
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
                        'projects' => [
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

        it('returns meaningful error when CLI returns empty error string', function () {
            $node = Node::factory()->create();

            $this->commandService->shouldReceive('executeCommand')
                ->once()
                ->andReturn(['success' => false, 'error' => '']);

            $result = $this->service->syncNode($node);

            expect($result['success'])->toBeFalse();
            expect($result['error'])->not->toBeEmpty();
            expect($result['error'])->toBe('Failed to list projects on node');
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

    describe('deployProject', function () {
        it('does not create DNS for failed deployments', function () {
            $node = Node::factory()->client()->production()->create(['external_host' => '1.2.3.4']);
            $project = GatewayProject::factory()->create([
                'slug' => 'failed-app',
                'production_domain' => 'failed.com',
                'cloudflare_zone_id' => 'zone123',
            ]);

            $this->orchestrator->shouldReceive('detectPhpVersion')->andReturn('8.5');
            $this->orchestrator->shouldReceive('deploy')
                ->once()
                ->andReturn(['success' => false, 'error' => 'Clone failed']);

            $this->cloudflareService->shouldReceive('createRecord')->never();
            $this->cloudflareService->shouldReceive('isConfigured')->never();

            $deployment = $this->service->deployProject($project, $node);

            expect($deployment->status)->toBe(DeploymentStatus::Failed);
            expect($deployment->cloudflare_record_id)->toBeNull();
        });

        it('cleans up DNS when re-deployment fails', function () {
            $node = Node::factory()->client()->production()->create(['external_host' => '1.2.3.4']);
            $project = GatewayProject::factory()->create([
                'slug' => 'redeploy-fail',
                'production_domain' => 'redeploy.com',
                'cloudflare_zone_id' => 'zone123',
            ]);

            // Create existing Failed/Removed deployment with DNS record
            Deployment::create([
                'node_id' => $node->id,
                'project_slug' => 'redeploy-fail',
                'project_name' => 'Redeploy Fail',
                'status' => DeploymentStatus::Removed,
                'cloudflare_record_id' => 'cf-rec-old',
                'gateway_project_id' => $project->id,
            ]);

            $this->orchestrator->shouldReceive('detectPhpVersion')->andReturn('8.5');
            $this->orchestrator->shouldReceive('deploy')
                ->once()
                ->andReturn(['success' => false, 'error' => 'Deploy failed']);

            $this->cloudflareService->shouldReceive('isConfigured')
                ->once()
                ->with('zone123')
                ->andReturn(true);

            $this->cloudflareService->shouldReceive('deleteRecord')
                ->once()
                ->with('cf-rec-old', 'zone123');

            $deployment = $this->service->deployProject($project, $node);

            expect($deployment->status)->toBe(DeploymentStatus::Failed);
            expect($deployment->fresh()->cloudflare_record_id)->toBeNull();
        });

        it('creates proxied DNS records for production nodes', function () {
            $node = Node::factory()->client()->production()->create(['external_host' => '1.2.3.4']);
            $project = GatewayProject::factory()->create([
                'slug' => 'proxied-test',
                'production_domain' => 'proxied.com',
                'cloudflare_zone_id' => 'zone123',
            ]);

            $this->orchestrator->shouldReceive('detectPhpVersion')->andReturn('8.5');
            $this->orchestrator->shouldReceive('deploy')
                ->once()
                ->andReturn(['success' => true, 'domain' => 'proxied.com', 'url' => 'https://proxied.com']);

            $this->cloudflareService->shouldReceive('setSslMode')
                ->once()
                ->with('zone123', 'strict');

            $this->cloudflareService->shouldReceive('isConfigured')
                ->once()
                ->with('zone123')
                ->andReturn(true);

            $this->cloudflareService->shouldReceive('isDomainAvailable')
                ->once()
                ->with('proxied.com', 'zone123')
                ->andReturn(true);

            $this->cloudflareService->shouldReceive('createRecord')
                ->once()
                ->withArgs(fn ($domain, $ip, $type, $proxied, $zoneId) => $domain === 'proxied.com' && $ip === '1.2.3.4' && $type === 'A' && $proxied === true && $zoneId === 'zone123')
                ->andReturn(['id' => 'cf-rec-789']);

            $this->cloudflareService->shouldReceive('purgeCache')
                ->once()
                ->with('zone123');

            $deployment = $this->service->deployProject($project, $node);

            expect($deployment->cloudflare_record_id)->toBe('cf-rec-789');
        });

        it('creates unproxied DNS records for dev nodes', function () {
            $node = Node::factory()->client()->development()->create(['external_host' => '1.2.3.4', 'tld' => 'bear']);
            $project = GatewayProject::factory()->create([
                'slug' => 'dev-test',
                'cloudflare_zone_id' => 'zone123',
            ]);

            $this->commandService->shouldReceive('executeCommand')
                ->once()
                ->andReturn(['success' => true, 'data' => ['domain' => 'dev-test.bear']]);

            $this->cloudflareService->shouldReceive('setSslMode')->never();

            $this->cloudflareService->shouldReceive('isConfigured')
                ->once()
                ->with('zone123')
                ->andReturn(true);

            $this->cloudflareService->shouldReceive('isDomainAvailable')
                ->once()
                ->andReturn(true);

            $this->cloudflareService->shouldReceive('createRecord')
                ->once()
                ->withArgs(fn ($domain, $ip, $type, $proxied) => $proxied === false)
                ->andReturn(['id' => 'cf-rec-dev']);

            $this->cloudflareService->shouldReceive('purgeCache')
                ->once()
                ->with('zone123');

            $deployment = $this->service->deployProject($project, $node);

            expect($deployment->cloudflare_record_id)->toBe('cf-rec-dev');
        });

        it('purges cloudflare cache after successful deploy', function () {
            $node = Node::factory()->client()->production()->create(['external_host' => '1.2.3.4']);
            $project = GatewayProject::factory()->create([
                'slug' => 'purge-test',
                'production_domain' => 'purge.com',
                'cloudflare_zone_id' => 'zone-purge',
            ]);

            $this->orchestrator->shouldReceive('detectPhpVersion')->andReturn('8.5');
            $this->orchestrator->shouldReceive('deploy')
                ->once()
                ->andReturn(['success' => true, 'domain' => 'purge.com']);

            $this->cloudflareService->shouldReceive('setSslMode')->once();
            $this->cloudflareService->shouldReceive('isConfigured')->with('zone-purge')->andReturn(true);
            $this->cloudflareService->shouldReceive('isDomainAvailable')->andReturn(true);
            $this->cloudflareService->shouldReceive('createRecord')->andReturn(['id' => 'rec-1']);

            $this->cloudflareService->shouldReceive('purgeCache')
                ->once()
                ->with('zone-purge')
                ->andReturn(true);

            $this->service->deployProject($project, $node);
        });

        it('succeeds even when cache purge fails', function () {
            $node = Node::factory()->client()->production()->create(['external_host' => '1.2.3.4']);
            $project = GatewayProject::factory()->create([
                'slug' => 'purge-fail',
                'production_domain' => 'purgefail.com',
                'cloudflare_zone_id' => 'zone-fail',
            ]);

            $this->orchestrator->shouldReceive('detectPhpVersion')->andReturn('8.5');
            $this->orchestrator->shouldReceive('deploy')
                ->once()
                ->andReturn(['success' => true, 'domain' => 'purgefail.com']);

            $this->cloudflareService->shouldReceive('setSslMode')->once();
            $this->cloudflareService->shouldReceive('isConfigured')->with('zone-fail')->andReturn(true);
            $this->cloudflareService->shouldReceive('isDomainAvailable')->andReturn(true);
            $this->cloudflareService->shouldReceive('createRecord')->andReturn(['id' => 'rec-2']);

            $this->cloudflareService->shouldReceive('purgeCache')
                ->once()
                ->with('zone-fail')
                ->andThrow(new \RuntimeException('API timeout'));

            $deployment = $this->service->deployProject($project, $node);

            expect($deployment->status)->toBe(DeploymentStatus::Active);
        });

        it('purges cache when undeploying with cloudflare record', function () {
            $node = Node::factory()->create();
            $project = GatewayProject::factory()->create([
                'slug' => 'undeploy-purge',
                'cloudflare_zone_id' => 'zone-undeploy',
            ]);
            $deployment = Deployment::create([
                'node_id' => $node->id,
                'project_slug' => 'undeploy-purge',
                'project_name' => 'Undeploy Purge',
                'status' => DeploymentStatus::Active,
                'cloudflare_record_id' => 'cf-rec-undeploy',
                'gateway_project_id' => $project->id,
            ]);

            $this->commandService->shouldReceive('executeCommand')
                ->andReturn(['success' => true]);

            $this->cloudflareService->shouldReceive('isConfigured')
                ->once()
                ->with('zone-undeploy')
                ->andReturn(true);

            $this->cloudflareService->shouldReceive('deleteRecord')
                ->once()
                ->with('cf-rec-undeploy', 'zone-undeploy');

            $this->cloudflareService->shouldReceive('purgeCache')
                ->once()
                ->with('zone-undeploy');

            $this->service->undeploy($deployment);
        });
    });
});
