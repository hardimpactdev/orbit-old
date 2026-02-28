<?php

use HardImpact\Orbit\Data\Gateway\Node;
use HardImpact\Orbit\GatewayConnector;
use HardImpact\Orbit\Resources\Gateway\NodeResource;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

it('lists nodes via gateway MCP', function () {
    $mockClient = new MockClient([
        '*' => MockResponse::make([
            'jsonrpc' => '2.0',
            'result' => [
                'content' => [
                    ['type' => 'text', 'text' => json_encode([
                        'success' => true,
                        'nodes' => [
                            [
                                'id' => 1,
                                'name' => 'gateway',
                                'host' => '10.6.0.2',
                                'external_host' => '185.1.2.3',
                                'environment' => 'production',
                                'node_type' => 'gateway',
                                'status' => 'active',
                                'is_active' => true,
                                'tld' => 'gateway',
                                'deployments_count' => 2,
                            ],
                            [
                                'id' => 2,
                                'name' => 'dev-server',
                                'host' => '10.6.0.3',
                                'external_host' => '185.1.2.4',
                                'environment' => 'development',
                                'node_type' => 'client',
                                'status' => 'active',
                                'is_active' => false,
                                'tld' => 'bear',
                                'deployments_count' => 15,
                            ],
                        ],
                    ])],
                ],
            ],
            'id' => 1,
        ]),
    ]);

    $connector = new GatewayConnector('https://orbit.test/mcp/gateway');
    $connector->withMockClient($mockClient);

    $resource = new NodeResource($connector);
    $nodes = $resource->list();

    expect($nodes)
        ->toBeArray()
        ->toHaveCount(2)
        ->and($nodes[0])
        ->toBeInstanceOf(Node::class)
        ->name->toBe('gateway')
        ->environment->toBe('production')
        ->and($nodes[1])
        ->name->toBe('dev-server')
        ->deploymentsCount->toBe(15);
});
