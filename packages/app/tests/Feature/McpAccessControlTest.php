<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

beforeEach(function () {
    // Create a test route that uses the middleware
    Route::middleware(\HardImpact\Orbit\App\Http\Middleware\McpAccessControl::class)
        ->post('/test-mcp', fn () => response()->json(['success' => true]));
});

it('allows access from localhost', function () {
    $response = $this->post('/test-mcp', [], ['REMOTE_ADDR' => '127.0.0.1']);

    $response->assertOk();
    $response->assertJson(['success' => true]);
});

it('allows access from gateway VPN subnet', function () {
    $vpnIps = [
        '10.6.0.1',   // Gateway WireGuard container
        '10.6.0.2',   // Gateway host VPN IP
        '10.6.0.50',  // Example VPN client
        '10.6.0.254', // End of subnet
    ];

    foreach ($vpnIps as $ip) {
        $response = $this->post('/test-mcp', [], ['REMOTE_ADDR' => $ip]);

        $response->assertOk();
        $response->assertJson(['success' => true]);
    }
});

it('blocks access from unauthorized IPs', function () {
    $unauthorizedIps = [
        '8.8.8.8',        // Public internet
        '192.168.1.100',  // Private LAN (not VPN)
        '10.5.0.1',       // Different 10.x subnet
        '10.7.0.1',       // Adjacent 10.x subnet
        '172.16.0.1',     // RFC1918 private
    ];

    foreach ($unauthorizedIps as $ip) {
        $response = $this->post('/test-mcp', [], ['REMOTE_ADDR' => $ip]);

        $response->assertForbidden();
        $response->assertSee('MCP access restricted');
    }
});

it('logs blocked access attempts', function () {
    Log::spy();

    $this->post('/test-mcp', [], ['REMOTE_ADDR' => '8.8.8.8']);

    Log::shouldHaveReceived('warning')
        ->once()
        ->with('MCP access denied', \Mockery::on(fn ($context) => $context['ip'] === '8.8.8.8'
            && str_contains($context['path'], 'test-mcp')
        ));
});

it('handles IPv4 CIDR subnet calculations correctly', function () {
    $middleware = new \HardImpact\Orbit\App\Http\Middleware\McpAccessControl;
    $reflection = new ReflectionClass($middleware);
    $method = $reflection->getMethod('ipInSubnet');
    $method->setAccessible(true);

    // Test /32 (single IP)
    expect($method->invoke($middleware, '127.0.0.1', '127.0.0.1/32'))->toBeTrue();
    expect($method->invoke($middleware, '127.0.0.2', '127.0.0.1/32'))->toBeFalse();

    // Test /24 (256 IPs)
    expect($method->invoke($middleware, '10.6.0.1', '10.6.0.0/24'))->toBeTrue();
    expect($method->invoke($middleware, '10.6.0.255', '10.6.0.0/24'))->toBeTrue();
    expect($method->invoke($middleware, '10.6.1.1', '10.6.0.0/24'))->toBeFalse();

    // Test /16 (65536 IPs)
    expect($method->invoke($middleware, '192.168.1.1', '192.168.0.0/16'))->toBeTrue();
    expect($method->invoke($middleware, '192.168.255.255', '192.168.0.0/16'))->toBeTrue();
    expect($method->invoke($middleware, '192.169.1.1', '192.168.0.0/16'))->toBeFalse();
});
