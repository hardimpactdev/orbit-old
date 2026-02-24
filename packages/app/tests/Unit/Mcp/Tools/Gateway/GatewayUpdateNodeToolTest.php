<?php

declare(strict_types=1);

use HardImpact\Orbit\Core\Enums\NodeEnvironment;
use HardImpact\Orbit\Core\Enums\NodeStatus;
use HardImpact\Orbit\Core\Models\Node;

beforeEach(function () {
    Node::query()->delete();
});

describe('GatewayUpdateNodeTool', function () {
    it('updates node host', function () {
        $node = Node::factory()->client()->create(['host' => '192.168.1.100']);

        $node->update(['host' => '10.6.0.7']);
        $node->refresh();

        expect($node->host)->toBe('10.6.0.7');
    });

    it('updates multiple fields at once', function () {
        $node = Node::factory()->client()->create([
            'name' => 'Old Name',
            'host' => '192.168.1.100',
            'external_host' => null,
        ]);

        $node->update([
            'name' => 'New Name',
            'host' => '10.6.0.7',
            'external_host' => '5.6.7.8',
        ]);
        $node->refresh();

        expect($node->name)->toBe('New Name');
        expect($node->host)->toBe('10.6.0.7');
        expect($node->external_host)->toBe('5.6.7.8');
    });

    it('updates node environment', function () {
        $node = Node::factory()->client()->development()->create();

        $node->update(['environment' => NodeEnvironment::Production]);
        $node->refresh();

        expect($node->environment)->toBe(NodeEnvironment::Production);
    });

    it('updates node status', function () {
        $node = Node::factory()->client()->create(['status' => NodeStatus::Error]);

        $node->update(['status' => NodeStatus::Active]);
        $node->refresh();

        expect($node->status)->toBe(NodeStatus::Active);
    });

    it('rejects invalid host', function () {
        $node = Node::factory()->client()->create();

        expect(fn () => $node->update(['host' => 'not valid host!']))
            ->toThrow(\InvalidArgumentException::class);
    });

    it('rejects invalid ssh user', function () {
        $node = Node::factory()->client()->create();

        expect(fn () => $node->update(['user' => 'invalid user!']))
            ->toThrow(\InvalidArgumentException::class);
    });

    it('returns null for non-existent node', function () {
        expect(Node::find(9999))->toBeNull();
    });

    it('updates is_active flag', function () {
        $node = Node::factory()->client()->create(['is_active' => false]);

        $node->update(['is_active' => true]);
        $node->refresh();

        expect($node->is_active)->toBeTrue();
    });
});
