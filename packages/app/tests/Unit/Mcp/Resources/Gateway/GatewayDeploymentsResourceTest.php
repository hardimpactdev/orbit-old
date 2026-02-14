<?php

declare(strict_types=1);

use HardImpact\Orbit\Core\Enums\DeploymentStatus;
use HardImpact\Orbit\Core\Models\Deployment;
use HardImpact\Orbit\Core\Models\Node;

beforeEach(function () {
    Node::query()->delete();
});

describe('GatewayDeploymentsResource', function () {
    it('groups deployments by project slug', function () {
        $devNode = Node::factory()->client()->development()->create(['name' => 'Dev']);
        $prodNode = Node::factory()->client()->production()->create(['name' => 'Prod']);

        Deployment::create([
            'node_id' => $devNode->id,
            'project_slug' => 'my-app',
            'project_name' => 'My App',
            'status' => DeploymentStatus::Active,
            'domain' => 'my-app.ccc',
        ]);

        Deployment::create([
            'node_id' => $prodNode->id,
            'project_slug' => 'my-app',
            'project_name' => 'My App',
            'status' => DeploymentStatus::Active,
            'domain' => 'my-app.com',
        ]);

        Deployment::create([
            'node_id' => $devNode->id,
            'project_slug' => 'other-app',
            'project_name' => 'Other App',
            'status' => DeploymentStatus::Active,
        ]);

        $deployments = Deployment::with('node')->get();
        $grouped = $deployments->groupBy('project_slug');

        expect($grouped)->toHaveCount(2);
        expect($grouped->get('my-app'))->toHaveCount(2);
        expect($grouped->get('other-app'))->toHaveCount(1);
    });

    it('includes node environment in deployment data', function () {
        $node = Node::factory()->client()->production()->create();

        Deployment::create([
            'node_id' => $node->id,
            'project_slug' => 'app',
            'project_name' => 'App',
            'status' => DeploymentStatus::Active,
        ]);

        $deployment = Deployment::with('node')->first();

        expect($deployment->node->environment->value)->toBe('production');
    });
});
