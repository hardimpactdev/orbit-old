<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;

it('protects mcp/gateway endpoint with access control', function () {
    // Test from unauthorized IP
    $response = $this->post('/mcp/gateway', [
        'jsonrpc' => '2.0',
        'method' => 'initialize',
        'params' => [
            'protocolVersion' => '2024-11-05',
            'capabilities' => [],
            'clientInfo' => ['name' => 'test', 'version' => '1.0'],
        ],
        'id' => 1,
    ], ['REMOTE_ADDR' => '8.8.8.8']);

    $response->assertForbidden();
    $response->assertSee('MCP access restricted');
});

it('protects mcp/orbit endpoint with access control', function () {
    // Test from unauthorized IP
    $response = $this->post('/mcp/orbit', [
        'jsonrpc' => '2.0',
        'method' => 'initialize',
        'params' => [
            'protocolVersion' => '2024-11-05',
            'capabilities' => [],
            'clientInfo' => ['name' => 'test', 'version' => '1.0'],
        ],
        'id' => 1,
    ], ['REMOTE_ADDR' => '203.0.113.1']);

    $response->assertForbidden();
    $response->assertSee('MCP access restricted');
});

it('allows mcp/gateway access from VPN network', function () {
    // Test from VPN IP - should NOT be blocked by middleware
    $response = $this->post('/mcp/gateway', [
        'jsonrpc' => '2.0',
        'method' => 'initialize',
        'params' => [
            'protocolVersion' => '2024-11-05',
            'capabilities' => [],
            'clientInfo' => ['name' => 'test', 'version' => '1.0'],
        ],
        'id' => 1,
    ], ['REMOTE_ADDR' => '10.6.0.50']);

    // Middleware should allow the request through (not return 403)
    $response->assertOk();
    $response->assertJsonStructure(['jsonrpc']); // Has JSON-RPC response
});

it('allows mcp/orbit access from localhost', function () {
    // Test from localhost - should NOT be blocked by middleware
    $response = $this->post('/mcp/orbit', [
        'jsonrpc' => '2.0',
        'method' => 'initialize',
        'params' => [
            'protocolVersion' => '2024-11-05',
            'capabilities' => [],
            'clientInfo' => ['name' => 'test', 'version' => '1.0'],
        ],
        'id' => 1,
    ], ['REMOTE_ADDR' => '127.0.0.1']);

    // Middleware should allow the request through (not return 403)
    $response->assertOk();
    $response->assertJsonStructure(['jsonrpc']); // Has JSON-RPC response
});

it('logs all blocked MCP access attempts with details', function () {
    Log::spy();

    $userAgent = 'Mozilla/5.0 (Attacker Bot)';

    $this->post('/mcp/gateway', [], [
        'REMOTE_ADDR' => '203.0.113.100',
        'HTTP_USER_AGENT' => $userAgent,
    ]);

    Log::shouldHaveReceived('warning')
        ->once()
        ->with('MCP access denied', \Mockery::on(fn ($context) => $context['ip'] === '203.0.113.100'
            && str_contains($context['path'], 'mcp/gateway')
            && $context['user_agent'] === $userAgent
        ));
});

it('blocks access from private LANs that are not the VPN', function () {
    $privateLanIps = [
        '192.168.1.100',  // Common home network
        '172.16.0.1',     // RFC1918 private
        '10.0.0.1',       // AWS/cloud private (not our VPN)
        '10.5.0.1',       // Different 10.x subnet
        '10.7.0.1',       // Adjacent 10.x subnet
    ];

    foreach ($privateLanIps as $ip) {
        $response = $this->post('/mcp/gateway', [], ['REMOTE_ADDR' => $ip]);

        expect($response->status())
            ->toBe(403, "IP {$ip} should be blocked but was allowed");
    }
});

it('allows access from all IPs in the VPN subnet', function () {
    $vpnIps = [
        '10.6.0.1',     // Gateway WireGuard
        '10.6.0.2',     // Gateway host
        '10.6.0.10',    // VPN client
        '10.6.0.100',   // VPN client
        '10.6.0.254',   // End of /24 range
    ];

    foreach ($vpnIps as $ip) {
        $response = $this->post('/mcp/gateway', [
            'jsonrpc' => '2.0',
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2024-11-05',
                'capabilities' => [],
                'clientInfo' => ['name' => 'test', 'version' => '1.0'],
            ],
            'id' => 1,
        ], ['REMOTE_ADDR' => $ip]);

        expect($response->status())
            ->toBe(200, "IP {$ip} should be allowed but was blocked");
    }
});
