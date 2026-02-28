<?php

use HardImpact\Orbit\Data\Gateway\Deployment;
use HardImpact\Orbit\GatewayConnector;
use HardImpact\Orbit\Resources\Gateway\DeploymentResource;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

function mcpResponse(array $data): MockResponse
{
    return MockResponse::make([
        'jsonrpc' => '2.0',
        'result' => [
            'content' => [
                ['type' => 'text', 'text' => json_encode($data)],
            ],
        ],
        'id' => 1,
    ]);
}

it('lists deployments via gateway MCP', function () {
    $mockClient = new MockClient([
        '*' => mcpResponse([
            'success' => true,
            'deployments' => [
                [
                    'id' => 1,
                    'project_slug' => 'my-app',
                    'project_name' => 'My App',
                    'github_repo' => 'org/my-app',
                    'domain' => 'my-app.bear',
                    'url' => 'https://my-app.bear',
                    'php_version' => '8.4',
                    'status' => 'active',
                    'error_message' => null,
                    'cloudflare_record_id' => null,
                    'node_id' => 2,
                    'node_name' => 'dev-server',
                    'node_environment' => 'development',
                    'created_at' => '2026-02-15T14:30:22Z',
                ],
            ],
        ]),
    ]);

    $connector = new GatewayConnector('https://orbit.test/mcp/gateway');
    $connector->withMockClient($mockClient);

    $resource = new DeploymentResource($connector);
    $deployments = $resource->list();

    expect($deployments)
        ->toBeArray()
        ->toHaveCount(1)
        ->and($deployments[0])
        ->toBeInstanceOf(Deployment::class)
        ->projectSlug->toBe('my-app')
        ->status->toBe('active');
});

it('deploys a project via gateway MCP', function () {
    $mockClient = new MockClient([
        '*' => mcpResponse([
            'success' => true,
            'deployment' => [
                'id' => 42,
                'project_slug' => 'my-app',
                'project_name' => 'My App',
                'github_repo' => 'org/my-app',
                'domain' => 'myapp.nl',
                'url' => 'https://myapp.nl',
                'php_version' => '8.4',
                'status' => 'active',
                'error_message' => null,
                'cloudflare_record_id' => 'cf-abc-123',
                'node_id' => 3,
                'node_name' => 'production',
                'node_environment' => 'production',
                'created_at' => '2026-02-15T15:01:34Z',
            ],
        ]),
    ]);

    $connector = new GatewayConnector('https://orbit.test/mcp/gateway');
    $connector->withMockClient($mockClient);

    $resource = new DeploymentResource($connector);
    $result = $resource->deploy(nodeId: 3, projectSlug: 'my-app');

    expect($result)
        ->toBeArray()
        ->toHaveKey('success', true)
        ->toHaveKey('deployment');

    expect($result['deployment'])
        ->toBeArray()
        ->toHaveKey('id', 42)
        ->toHaveKey('domain', 'myapp.nl');
});
