<?php

declare(strict_types=1);

namespace HardImpact\Orbit\App\Mcp\Tools\Gateway;

use HardImpact\Orbit\Core\Models\Node;
use HardImpact\Orbit\Core\Services\SshService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

final class GatewayFlushDnsTool extends Tool
{
    protected string $name = 'gateway_flush_dns';

    protected string $description = 'Flush DNS cache on the gateway (restart dnsmasq container)';

    public function __construct(
        protected SshService $ssh,
    ) {}

    public function shouldRegister(): bool
    {
        return Node::getSelf()?->isGateway() ?? false;
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): ResponseFactory
    {
        $node = Node::getSelf();

        if (! $node || ! $node->isGateway()) {
            return Response::structured([
                'success' => false,
                'error' => 'Not running on gateway node',
            ]);
        }

        // Restart dnsmasq container to flush cache
        $result = $this->ssh->execute($node, 'docker restart orbit-dnsmasq 2>&1');

        if (! $result['success']) {
            return Response::structured([
                'success' => false,
                'error' => 'Failed to restart dnsmasq: '.($result['error'] ?: $result['output']),
            ]);
        }

        return Response::structured([
            'success' => true,
            'message' => 'DNS cache flushed (dnsmasq container restarted)',
            'output' => $result['output'],
        ]);
    }
}
