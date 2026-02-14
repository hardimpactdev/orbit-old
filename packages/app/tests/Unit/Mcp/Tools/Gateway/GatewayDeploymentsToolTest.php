<?php

declare(strict_types=1);

use HardImpact\Orbit\Core\Enums\DeploymentStatus;
use HardImpact\Orbit\Core\Enums\NodeEnvironment;
use HardImpact\Orbit\Core\Models\Deployment;
use HardImpact\Orbit\Core\Models\Node;

beforeEach(function () {
    Node::query()->delete();
});

describe('GatewayDeploymentsTool', function () {
    it('lists all deployments with node info', function () {
        $node = Node::factory()->client()->production()->create(['name' => 'Prod']);

        Deployment::create([
            'node_id' => $node->id,
            'project_slug' => 'app-1',
            'project_name' => 'App 1',
            'status' => DeploymentStatus::Active,
            'domain' => 'app-1.com',
        ]);

        $deployments = Deployment::with('node')->get();

        expect($deployments)->toHaveCount(1);
        expect($deployments->first()->node->name)->toBe('Prod');
        expect($deployments->first()->domain)->toBe('app-1.com');
    });

    it('filters by project slug', function () {
        $node = Node::factory()->create();

        Deployment::create([
            'node_id' => $node->id,
            'project_slug' => 'target-app',
            'project_name' => 'Target',
            'status' => DeploymentStatus::Active,
        ]);

        Deployment::create([
            'node_id' => $node->id,
            'project_slug' => 'other-app',
            'project_name' => 'Other',
            'status' => DeploymentStatus::Active,
        ]);

        $filtered = Deployment::where('project_slug', 'target-app')->get();

        expect($filtered)->toHaveCount(1);
    });

    it('filters by node environment', function () {
        $prodNode = Node::factory()->client()->production()->create();
        $devNode = Node::factory()->client()->development()->create();

        Deployment::create([
            'node_id' => $prodNode->id,
            'project_slug' => 'app',
            'project_name' => 'App',
            'status' => DeploymentStatus::Active,
        ]);

        Deployment::create([
            'node_id' => $devNode->id,
            'project_slug' => 'app-dev',
            'project_name' => 'App Dev',
            'status' => DeploymentStatus::Active,
        ]);

        $prodDeployments = Deployment::whereHas('node', fn ($q) => $q->where('environment', NodeEnvironment::Production))->get();

        expect($prodDeployments)->toHaveCount(1);
        expect($prodDeployments->first()->project_slug)->toBe('app');
    });

    it('filters by deployment status', function () {
        $node = Node::factory()->create();

        Deployment::create([
            'node_id' => $node->id,
            'project_slug' => 'active-app',
            'project_name' => 'Active',
            'status' => DeploymentStatus::Active,
        ]);

        Deployment::create([
            'node_id' => $node->id,
            'project_slug' => 'failed-app',
            'project_name' => 'Failed',
            'status' => DeploymentStatus::Failed,
        ]);

        $active = Deployment::where('status', DeploymentStatus::Active)->get();

        expect($active)->toHaveCount(1);
        expect($active->first()->project_slug)->toBe('active-app');
    });
});
