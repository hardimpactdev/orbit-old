<?php

use App\Services\GatewayCliAdapter;
use HardImpact\Orbit\Core\Models\Gateway;
use HardImpact\Orbit\Core\Services\Gateway\GatewayManager;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    $this->gatewayManager = Mockery::mock('GatewayManagerAlias');
    $this->adapter = Mockery::mock('GatewayCliAdapterAlias');

    $this->app->instance(GatewayManager::class, $this->gatewayManager);
    $this->app->instance(GatewayCliAdapter::class, $this->adapter);

    // Ensure gateways table exists for Gateway::find()
    if (! Schema::hasTable('gateways')) {
        Schema::create('gateways', function ($table) {
            $table->id();
            $table->string('name');
            $table->string('ip_address');
            $table->string('ssh_user')->default('orbit');
            $table->string('status')->default('active');
            $table->string('subnet')->nullable();
            $table->string('wg_password')->nullable();
            $table->integer('wg_api_port')->default(51821);
            $table->timestamp('last_connected_at')->nullable();
            $table->timestamps();
        });
    }

    Gateway::query()->delete();
    $this->gateway = Gateway::create([
        'id' => 1,
        'name' => 'test-gateway',
        'ip_address' => '188.245.156.201',
        'ssh_user' => 'gateway',
        'subnet' => '10.8.0.0/24',
    ]);
});

it('stores token and lists available zones', function () {
    $this->gatewayManager->shouldReceive('hasAny')->andReturn(true);
    $this->adapter->shouldReceive('detectActive')->andReturn([
        'gateway' => $this->gateway,
        'vpn_ip' => '10.8.0.2',
    ]);

    $zonesJson = json_encode([
        'success' => true,
        'data' => ['zones' => [
            ['id' => 'zone-1', 'name' => 'example.com'],
            ['id' => 'zone-2', 'name' => 'another.com'],
        ]],
    ]);

    Process::fake([
        '*cloudflare:zones*' => Process::result(output: $zonesJson),
        '*cloudflare:store*' => Process::result(output: 'Cloudflare API token stored.'),
    ]);

    $this->artisan('cloudflare:configure')
        ->expectsQuestion('Cloudflare API token', 'cf-test-token')
        ->expectsOutputToContain('Cloudflare API token stored')
        ->expectsOutputToContain('example.com, another.com')
        ->assertExitCode(0);
});

it('fails when no gateways configured', function () {
    $this->gatewayManager->shouldReceive('hasAny')->andReturn(false);

    $this->artisan('cloudflare:configure')
        ->expectsOutputToContain('No gateways configured')
        ->assertExitCode(1);
});

it('fails when no active VPN connection', function () {
    $this->gatewayManager->shouldReceive('hasAny')->andReturn(true);
    $this->adapter->shouldReceive('detectActive')->andReturn(null);

    $this->artisan('cloudflare:configure')
        ->expectsOutputToContain('No active WireGuard connection')
        ->assertExitCode(1);
});

it('fails when token validation returns error', function () {
    $this->gatewayManager->shouldReceive('hasAny')->andReturn(true);
    $this->adapter->shouldReceive('detectActive')->andReturn([
        'gateway' => $this->gateway,
        'vpn_ip' => '10.8.0.2',
    ]);

    $errorJson = json_encode([
        'success' => false,
        'error' => 'No zones found for this token.',
    ]);

    Process::fake([
        '*cloudflare:zones*' => Process::result(output: $errorJson, exitCode: 1),
    ]);

    $this->artisan('cloudflare:configure')
        ->expectsQuestion('Cloudflare API token', 'bad-token')
        ->expectsOutputToContain('Could not retrieve zones')
        ->assertExitCode(1);
});

it('fails when SSH connection fails', function () {
    $this->gatewayManager->shouldReceive('hasAny')->andReturn(true);
    $this->adapter->shouldReceive('detectActive')->andReturn([
        'gateway' => $this->gateway,
        'vpn_ip' => '10.8.0.2',
    ]);

    Process::fake([
        '*' => Process::result(output: '', exitCode: 255),
    ]);

    $this->artisan('cloudflare:configure')
        ->expectsQuestion('Cloudflare API token', 'cf-test-token')
        ->expectsOutputToContain('Failed to validate token')
        ->assertExitCode(1);
});
