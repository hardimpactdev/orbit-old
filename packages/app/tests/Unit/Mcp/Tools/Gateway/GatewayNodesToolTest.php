<?php

declare(strict_types=1);

use HardImpact\Orbit\Core\Enums\DeploymentStatus;
use HardImpact\Orbit\Core\Enums\NodeEnvironment;
use HardImpact\Orbit\Core\Models\Deployment;
use HardImpact\Orbit\Core\Models\Node;

beforeEach(function () {
    Node::query()->delete();
});

describe('GatewayNodesTool', function () {
    it('lists all non-gateway nodes with deployment counts', function () {
        $client = Node::factory()->client()->production()->create([
            'name' => 'Prod Server',
            'external_host' => '1.2.3.4',
        ]);

        Deployment::create([
            'node_id' => $client->id,
            'project_slug' => 'app-1',
            'project_name' => 'App 1',
            'status' => DeploymentStatus::Active,
        ]);

        Deployment::create([
            'node_id' => $client->id,
            'project_slug' => 'app-2',
            'project_name' => 'App 2',
            'status' => DeploymentStatus::Active,
        ]);

        Node::factory()->gateway()->create(['name' => 'Gateway']);

        $nodes = Node::where('node_type', '!=', 'gateway')
            ->withCount('deployments')
            ->get();

        expect($nodes)->toHaveCount(1);
        expect($nodes->first()->name)->toBe('Prod Server');
        expect($nodes->first()->deployments_count)->toBe(2);
    });

    it('filters nodes by environment', function () {
        Node::factory()->client()->production()->create(['name' => 'Prod']);
        Node::factory()->client()->development()->create(['name' => 'Dev']);
        Node::factory()->client()->staging()->create(['name' => 'Staging']);

        $prodNodes = Node::where('environment', NodeEnvironment::Production)
            ->where('node_type', '!=', 'gateway')
            ->get();

        expect($prodNodes)->toHaveCount(1);
        expect($prodNodes->first()->name)->toBe('Prod');
    });
});
