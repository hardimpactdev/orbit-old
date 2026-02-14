<?php

declare(strict_types=1);

use HardImpact\Orbit\Core\Enums\NodeEnvironment;
use HardImpact\Orbit\Core\Enums\NodeType;
use HardImpact\Orbit\Core\Models\Deployment;
use HardImpact\Orbit\Core\Models\Node;

describe('Node environment', function () {
    it('defaults to development', function () {
        $node = Node::factory()->create();

        expect($node->environment)->toBe(NodeEnvironment::Development);
    });

    it('casts environment to enum', function () {
        $node = Node::factory()->production()->create();

        expect($node->environment)->toBe(NodeEnvironment::Production);
        expect($node->isProduction())->toBeTrue();
        expect($node->isDevelopment())->toBeFalse();
        expect($node->isStaging())->toBeFalse();
    });

    it('isStaging works', function () {
        $node = Node::factory()->staging()->create();

        expect($node->isStaging())->toBeTrue();
        expect($node->isProduction())->toBeFalse();
    });

    it('isDevelopment works', function () {
        $node = Node::factory()->development()->create();

        expect($node->isDevelopment())->toBeTrue();
    });
});

describe('Node deployments relationship', function () {
    it('returns deployments for a node', function () {
        $node = Node::factory()->create();

        Deployment::create([
            'node_id' => $node->id,
            'project_slug' => 'project-a',
            'project_name' => 'Project A',
        ]);

        Deployment::create([
            'node_id' => $node->id,
            'project_slug' => 'project-b',
            'project_name' => 'Project B',
        ]);

        expect($node->deployments)->toHaveCount(2);
    });

    it('does not include other nodes deployments', function () {
        $node1 = Node::factory()->create();
        $node2 = Node::factory()->create();

        Deployment::create([
            'node_id' => $node1->id,
            'project_slug' => 'only-on-node1',
            'project_name' => 'Only Node 1',
        ]);

        Deployment::create([
            'node_id' => $node2->id,
            'project_slug' => 'only-on-node2',
            'project_name' => 'Only Node 2',
        ]);

        expect($node1->deployments)->toHaveCount(1);
        expect($node1->deployments->first()->project_slug)->toBe('only-on-node1');
    });

    it('cascades delete when node is deleted', function () {
        $node = Node::factory()->create();

        Deployment::create([
            'node_id' => $node->id,
            'project_slug' => 'will-be-deleted',
            'project_name' => 'Will be deleted',
        ]);

        expect(Deployment::count())->toBe(1);

        $node->delete();

        expect(Deployment::count())->toBe(0);
    });
});
