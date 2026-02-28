<?php

use HardImpact\Orbit\Data\InstanceInfo;
use HardImpact\Orbit\Data\Status;
use HardImpact\Orbit\OrbitConnector;
use HardImpact\Orbit\Resources\StatusResource;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

it('fetches node status', function () {
    $mockClient = new MockClient([
        '*' => MockResponse::make([
            'running' => true,
            'architecture' => 'x86_64',
            'services' => [
                'caddy' => ['status' => 'running', 'health' => 'healthy', 'container' => null, 'type' => 'host'],
            ],
            'servicesRunning' => 3,
            'servicesHealthy' => 3,
            'servicesTotal' => 5,
            'projectsCount' => 12,
            'configPath' => '/home/orbit/.config/orbit',
            'tld' => 'bear',
            'defaultPhpVersion' => '8.4',
            'cliVersion' => '0.1.0',
            'cliPath' => '/usr/local/bin/orbit',
        ]),
    ]);

    $connector = new OrbitConnector('https://orbit.test');
    $connector->withMockClient($mockClient);

    $resource = new StatusResource($connector);
    $status = $resource->get();

    expect($status)
        ->toBeInstanceOf(Status::class)
        ->running->toBeTrue()
        ->servicesRunning->toBe(3)
        ->tld->toBe('bear');
});

it('fetches instance info', function () {
    $mockClient = new MockClient([
        '*' => MockResponse::make([
            'name' => 'dev-server',
            'tld' => 'bear',
            'cli_version' => '0.1.0',
        ]),
    ]);

    $connector = new OrbitConnector('https://orbit.test');
    $connector->withMockClient($mockClient);

    $resource = new StatusResource($connector);
    $info = $resource->instanceInfo();

    expect($info)
        ->toBeInstanceOf(InstanceInfo::class)
        ->name->toBe('dev-server')
        ->tld->toBe('bear');
});
