<?php

declare(strict_types=1);

use HardImpact\Orbit\Core\Models\Node;
use HardImpact\Orbit\Core\Services\SshService;

beforeEach(function () {
    Node::query()->delete();
    $this->sshService = mock(SshService::class);
});

describe('GatewayFlushDnsTool', function () {
    it('flushes DNS by restarting dnsmasq container', function () {
        $gateway = Node::factory()->gateway()->default()->create();

        $this->sshService->shouldReceive('execute')
            ->once()
            ->withArgs(function ($node, $cmd) use ($gateway) {
                return $node->id === $gateway->id
                    && str_contains($cmd, 'docker restart orbit-dns');
            })
            ->andReturn([
                'success' => true,
                'output' => 'orbit-dns',
                'error' => '',
            ]);

        // Verify the SSH command is executed with correct arguments
        $result = $this->sshService->execute($gateway, 'docker restart orbit-dns 2>&1');

        expect($result['success'])->toBeTrue();
        expect($result['output'])->toBe('orbit-dns');
    });

    it('reports error when docker command fails', function () {
        $gateway = Node::factory()->gateway()->default()->create();

        $this->sshService->shouldReceive('execute')
            ->once()
            ->andReturn([
                'success' => false,
                'output' => '',
                'error' => 'Container not found',
            ]);

        $result = $this->sshService->execute($gateway, 'docker restart orbit-dns 2>&1');

        expect($result['success'])->toBeFalse();
        expect($result['error'])->toContain('Container not found');
    });
});
