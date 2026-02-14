<?php

declare(strict_types=1);

use HardImpact\Orbit\Core\Enums\DeploymentStatus;
use HardImpact\Orbit\Core\Models\Deployment;
use HardImpact\Orbit\Core\Models\Node;

describe('Deployment', function () {
    describe('creation', function () {
        it('creates a deployment with required fields', function () {
            $node = Node::factory()->create();

            $deployment = Deployment::create([
                'node_id' => $node->id,
                'project_slug' => 'my-project',
                'project_name' => 'My Project',
                'status' => DeploymentStatus::Active,
            ]);

            expect($deployment->project_slug)->toBe('my-project');
            expect($deployment->project_name)->toBe('My Project');
            expect($deployment->status)->toBe(DeploymentStatus::Active);
            expect($deployment->node_id)->toBe($node->id);
        });

        it('casts status to DeploymentStatus enum', function () {
            $node = Node::factory()->create();

            $deployment = Deployment::create([
                'node_id' => $node->id,
                'project_slug' => 'test',
                'project_name' => 'Test',
                'status' => 'deploying',
            ]);

            expect($deployment->status)->toBe(DeploymentStatus::Deploying);
        });

        it('casts metadata to array', function () {
            $node = Node::factory()->create();

            $deployment = Deployment::create([
                'node_id' => $node->id,
                'project_slug' => 'test',
                'project_name' => 'Test',
                'metadata' => ['template' => 'laravel'],
            ]);

            expect($deployment->metadata)->toBe(['template' => 'laravel']);
        });
    });

    describe('relationships', function () {
        it('belongs to a node', function () {
            $node = Node::factory()->create(['name' => 'Production Server']);

            $deployment = Deployment::create([
                'node_id' => $node->id,
                'project_slug' => 'test',
                'project_name' => 'Test',
            ]);

            expect($deployment->node->name)->toBe('Production Server');
        });
    });

    describe('helpers', function () {
        it('isActive returns true for active deployments', function () {
            $node = Node::factory()->create();
            $deployment = Deployment::create([
                'node_id' => $node->id,
                'project_slug' => 'test',
                'project_name' => 'Test',
                'status' => DeploymentStatus::Active,
            ]);

            expect($deployment->isActive())->toBeTrue();
            expect($deployment->isFailed())->toBeFalse();
            expect($deployment->isRemoved())->toBeFalse();
        });

        it('isFailed returns true for failed deployments', function () {
            $node = Node::factory()->create();
            $deployment = Deployment::create([
                'node_id' => $node->id,
                'project_slug' => 'test',
                'project_name' => 'Test',
                'status' => DeploymentStatus::Failed,
                'error_message' => 'Clone failed',
            ]);

            expect($deployment->isFailed())->toBeTrue();
            expect($deployment->isActive())->toBeFalse();
        });

        it('hasCloudflareRecord detects cloudflare record id', function () {
            $node = Node::factory()->create();

            $without = Deployment::create([
                'node_id' => $node->id,
                'project_slug' => 'no-cf',
                'project_name' => 'No CF',
            ]);

            $with = Deployment::create([
                'node_id' => $node->id,
                'project_slug' => 'with-cf',
                'project_name' => 'With CF',
                'cloudflare_record_id' => 'abc123',
            ]);

            expect($without->hasCloudflareRecord())->toBeFalse();
            expect($with->hasCloudflareRecord())->toBeTrue();
        });
    });

    describe('unique constraint', function () {
        it('enforces unique node_id + project_slug', function () {
            $node = Node::factory()->create();

            Deployment::create([
                'node_id' => $node->id,
                'project_slug' => 'duplicate-test',
                'project_name' => 'First',
            ]);

            expect(fn () => Deployment::create([
                'node_id' => $node->id,
                'project_slug' => 'duplicate-test',
                'project_name' => 'Second',
            ]))->toThrow(\Illuminate\Database\QueryException::class);
        });

        it('allows same slug on different nodes', function () {
            $node1 = Node::factory()->create();
            $node2 = Node::factory()->create();

            $d1 = Deployment::create([
                'node_id' => $node1->id,
                'project_slug' => 'shared-project',
                'project_name' => 'Shared',
            ]);

            $d2 = Deployment::create([
                'node_id' => $node2->id,
                'project_slug' => 'shared-project',
                'project_name' => 'Shared',
            ]);

            expect($d1->id)->not->toBe($d2->id);
        });
    });
});
