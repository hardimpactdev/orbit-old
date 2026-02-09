<?php

use HardImpact\Orbit\Core\Models\Node;
use HardImpact\Orbit\Core\Services\OrbitCli\ConfigurationService;
use HardImpact\Orbit\Core\Services\OrbitCli\ProjectCliService;

beforeEach(function () {
    createNode();
});

test('provision status endpoint returns not found for unknown project', function () {
    $node = Node::first();

    $this->mock(ProjectCliService::class, function ($mock) {
        $mock->shouldReceive('provisionStatus')
            ->with(Mockery::type(Node::class), 'unknown-project')
            ->andReturn([
                'success' => true,
                'data' => [
                    'status' => 'not_found',
                    'error' => null,
                ],
            ]);
    });

    $response = $this->get("/nodes/{$node->id}/projects/unknown-project/provision-status");

    $response->assertStatus(200);
    $response->assertJson([
        'success' => true,
        'data' => [
            'status' => 'not_found',
        ],
    ]);
});

test('provision status endpoint returns provisioning status', function () {
    $node = Node::first();

    $this->mock(ProjectCliService::class, function ($mock) {
        $mock->shouldReceive('provisionStatus')
            ->with(Mockery::type(Node::class), 'my-project')
            ->andReturn([
                'success' => true,
                'data' => [
                    'status' => 'cloning',
                    'error' => null,
                ],
            ]);
    });

    $response = $this->get("/nodes/{$node->id}/projects/my-project/provision-status");

    $response->assertStatus(200);
    $response->assertJson([
        'success' => true,
        'data' => [
            'status' => 'cloning',
        ],
    ]);
});

test('provision status endpoint returns ready when complete', function () {
    $node = Node::first();

    $this->mock(ProjectCliService::class, function ($mock) {
        $mock->shouldReceive('provisionStatus')
            ->with(Mockery::type(Node::class), 'my-project')
            ->andReturn([
                'success' => true,
                'data' => [
                    'status' => 'ready',
                    'error' => null,
                ],
            ]);
    });

    $response = $this->get("/nodes/{$node->id}/projects/my-project/provision-status");

    $response->assertStatus(200);
    $response->assertJson([
        'success' => true,
        'data' => [
            'status' => 'ready',
        ],
    ]);
});

test('provision status endpoint returns failed with error', function () {
    $node = Node::first();

    $this->mock(ProjectCliService::class, function ($mock) {
        $mock->shouldReceive('provisionStatus')
            ->with(Mockery::type(Node::class), 'my-project')
            ->andReturn([
                'success' => true,
                'data' => [
                    'status' => 'failed',
                    'error' => 'Failed to clone repository',
                ],
            ]);
    });

    $response = $this->get("/nodes/{$node->id}/projects/my-project/provision-status");

    $response->assertStatus(200);
    $response->assertJson([
        'success' => true,
        'data' => [
            'status' => 'failed',
            'error' => 'Failed to clone repository',
        ],
    ]);
});

test('reverb config endpoint returns config when enabled', function () {
    $node = Node::first();

    $this->mock(ConfigurationService::class, function ($mock) {
        $mock->shouldReceive('getReverbConfig')
            ->with(Mockery::type(Node::class))
            ->andReturn([
                'success' => true,
                'data' => [
                    'enabled' => true,
                    'host' => 'reverb.ccc',
                    'port' => 443,
                    'scheme' => 'https',
                    'app_key' => 'orbit-key',
                ],
            ]);
    });

    $response = $this->get("/nodes/{$node->id}/reverb-config");

    $response->assertStatus(200);
    $response->assertJson([
        'success' => true,
        'data' => [
            'enabled' => true,
            'host' => 'reverb.ccc',
            'port' => 443,
        ],
    ]);
});

test('reverb config endpoint returns disabled when not configured', function () {
    $node = Node::first();

    $this->mock(ConfigurationService::class, function ($mock) {
        $mock->shouldReceive('getReverbConfig')
            ->with(Mockery::type(Node::class))
            ->andReturn([
                'success' => true,
                'data' => [
                    'enabled' => false,
                ],
            ]);
    });

    $response = $this->get("/nodes/{$node->id}/reverb-config");

    $response->assertStatus(200);
    $response->assertJson([
        'success' => true,
        'data' => [
            'enabled' => false,
        ],
    ]);
});

test('create project page loads', function () {
    $node = Node::first();

    $response = $this->get("/nodes/{$node->id}/projects/create");

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page->component('nodes/projects/ProjectCreate'));
});

test('projects page loads', function () {
    $node = Node::first();

    $response = $this->get("/nodes/{$node->id}/projects");

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page->component('nodes/Projects'));
});

test('projects page includes provisioning slug from flash', function () {
    $node = Node::first();

    $response = $this->withSession(['flash' => ['provisioning' => 'my-new-project']])
        ->get("/nodes/{$node->id}/projects");

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page->component('nodes/Projects'));
});
