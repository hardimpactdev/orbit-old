<?php

use HardImpact\Orbit\Data\ProjectList;
use HardImpact\Orbit\Data\ProvisionStatus;
use HardImpact\Orbit\OrbitConnector;
use HardImpact\Orbit\Resources\ProjectResource;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

it('lists projects', function () {
    $mockClient = new MockClient([
        '*' => MockResponse::make([
            'projects' => [
                [
                    'name' => 'my-app',
                    'display_name' => 'My App',
                    'slug' => 'my-app',
                    'path' => '/home/orbit/projects/my-app',
                    'php_version' => '8.4',
                    'github_repo' => null,
                    'project_type' => 'laravel',
                    'hasPublicFolder' => true,
                    'domain' => 'my-app.bear',
                    'url' => 'https://my-app.bear',
                    'status' => 'ready',
                    'error_message' => null,
                    'job_id' => null,
                ],
            ],
            'tld' => 'bear',
            'defaultPhpVersion' => '8.4',
            'availablePhpVersions' => ['8.5', '8.4', '8.3'],
        ]),
    ]);

    $connector = new OrbitConnector('https://orbit.test');
    $connector->withMockClient($mockClient);

    $resource = new ProjectResource($connector);
    $result = $resource->list();

    expect($result)
        ->toBeInstanceOf(ProjectList::class)
        ->tld->toBe('bear')
        ->defaultPhpVersion->toBe('8.4');
});

it('deletes a project', function () {
    $mockClient = new MockClient([
        '*' => MockResponse::make(['success' => true], 200),
    ]);

    $connector = new OrbitConnector('https://orbit.test');
    $connector->withMockClient($mockClient);

    $resource = new ProjectResource($connector);

    expect($resource->delete('my-app'))->toBeTrue();
});

it('checks provision status', function () {
    $mockClient = new MockClient([
        '*' => MockResponse::make([
            'status' => 'provisioning',
            'slug' => 'my-app',
            'job_id' => 'abc-123',
            'error' => null,
            'output' => null,
        ]),
    ]);

    $connector = new OrbitConnector('https://orbit.test');
    $connector->withMockClient($mockClient);

    $resource = new ProjectResource($connector);
    $status = $resource->provisionStatus('my-app');

    expect($status)
        ->toBeInstanceOf(ProvisionStatus::class)
        ->status->toBe('provisioning')
        ->slug->toBe('my-app');
});
