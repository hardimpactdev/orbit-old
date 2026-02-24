<?php

declare(strict_types=1);

use HardImpact\Orbit\Core\Models\Node;
use HardImpact\Orbit\Core\Services\OrbitCli\ConfigurationService;
use HardImpact\Orbit\Core\Services\OrbitCli\StatusService;
use HardImpact\Orbit\Core\Services\SetupService;

beforeEach(function () {
    // Clean database before each test
    Node::query()->delete();

    // Mock SetupService to skip setup redirect in tests
    $this->mock(SetupService::class, function ($mock) {
        $mock->shouldReceive('needsSetup')->andReturn(false);
        $mock->shouldReceive('getStatus')->andReturn([
            'needs_setup' => false,
            'cli_installed' => true,
            'cli_version' => '1.0.0',
            'has_node' => true,
            'has_services' => true,
            'has_tld' => true,
            'steps' => [],
        ]);
    });

    // Mock the StatusService to avoid actual CLI calls
    $this->mock(StatusService::class, function ($mock) {
        $mock->shouldReceive('checkInstallation')->andReturn([
            'success' => true,
            'installed' => true,
            'version' => '1.0.0',
        ]);
        $mock->shouldReceive('status')->andReturn([
            'success' => true,
            'data' => [
                'services' => [],
                'projects' => [],
            ],
        ]);
    });

    // Mock ConfigurationService
    $this->mock(ConfigurationService::class, function ($mock) {
        $mock->shouldReceive('getConfig')->andReturn([
            'success' => true,
            'data' => ['tld' => 'test'],
        ]);
        $mock->shouldReceive('getReverbConfig')->andReturn([
            'success' => true,
            'enabled' => false,
        ]);
        $mock->shouldReceive('getFormattedReverbConfig')->andReturn([
            'enabled' => false,
        ]);
    });
});

describe('index', function () {
    it('redirects to single node when only one exists', function () {
        $node = Node::factory()->create();

        $response = $this->get(route('nodes.index'));

        $response->assertRedirect(route('nodes.show', $node));
    });

    it('shows index page when multiple nodes exist', function () {
        Node::factory()->count(2)->create();

        $response = $this->get(route('nodes.index'));

        $response->assertInertia(fn ($page) => $page->component('nodes/Index'));
    });

    it('auto-creates local node in desktop mode when none exist', function () {
        config()->set('orbit.mode', 'desktop');

        expect(Node::count())->toBe(0);

        $response = $this->get(route('nodes.index'));

        expect(Node::count())->toBe(1);
        $node = Node::first();
        expect($node->isLocal())->toBeTrue();
        expect($node->name)->toBe('Local');
        $response->assertRedirect(route('nodes.show', $node));
    });
});

describe('create', function () {
    it('shows create node form', function () {
        $response = $this->get(route('nodes.create'));

        $response->assertInertia(fn ($page) => $page
            ->component('nodes/Create')
            ->has('currentUser')
            ->has('hasLocalNode')
        );
    });

    it('indicates when local node already exists', function () {
        Node::factory()->local()->create();

        $response = $this->get(route('nodes.create'));

        $response->assertInertia(fn ($page) => $page
            ->where('hasLocalNode', true)
        );
    });
});

describe('store', function () {
    it('creates a new remote node', function () {
        $data = [
            'name' => 'Production Server',
            'host' => '192.168.1.100',
            'user' => 'deploy',
            'port' => 22,
        ];

        $response = $this->post(route('nodes.store'), $data);

        $response->assertRedirect();
        expect(Node::where('name', 'Production Server')->exists())->toBeTrue();
    });

    it('creates a local node when none exists', function () {
        $data = [
            'name' => 'Local',
            'host' => 'localhost',
            'user' => 'testuser',
            'port' => 22,
        ];

        $response = $this->post(route('nodes.store'), $data);

        $response->assertRedirect();
        $node = Node::where('host', 'localhost')->first();
        expect($node)->not->toBeNull();
        expect($node->name)->toBe('Local');
    });

    it('allows creating multiple local nodes', function () {
        Node::factory()->local()->create();

        $data = [
            'name' => 'Another Local',
            'host' => 'localhost',
            'user' => 'testuser',
            'port' => 22,
        ];

        $response = $this->post(route('nodes.store'), $data);

        $response->assertRedirect();
        expect(Node::query()->count())->toBe(2);
    });

    it('validates required fields', function () {
        $response = $this->post(route('nodes.store'), []);

        $response->assertSessionHasErrors(['name', 'host', 'user', 'port']);
    });

    it('validates port range', function () {
        $response = $this->post(route('nodes.store'), [
            'name' => 'Test',
            'host' => 'test.com',
            'user' => 'user',
            'port' => 99999,
        ]);

        $response->assertSessionHasErrors(['port']);
    });
});

describe('show', function () {
    it('shows node dashboard for active node', function () {
        $node = Node::factory()->create();

        $response = $this->get(route('nodes.show', $node));

        $response->assertInertia(fn ($page) => $page
            ->component('nodes/Show')
            ->has('node')
            ->has('installation')
            ->has('editor')
        );
    });

    it('shows provisioning view for node being provisioned', function () {
        $node = Node::factory()->provisioning()->create();

        $response = $this->get(route('nodes.show', $node));

        $response->assertInertia(fn ($page) => $page
            ->component('nodes/Provisioning')
            ->has('node')
        );
    });

    it('shows provisioning view for node with error', function () {
        $node = Node::factory()->withError()->create();

        $response = $this->get(route('nodes.show', $node));

        $response->assertInertia(fn ($page) => $page
            ->component('nodes/Provisioning')
            ->has('node')
        );
    });
});

describe('edit', function () {
    it('shows edit form for node', function () {
        $node = Node::factory()->create();

        $response = $this->get(route('nodes.edit', $node));

        $response->assertInertia(fn ($page) => $page
            ->component('nodes/Edit')
            ->has('node')
        );
    });
});

describe('update', function () {
    it('updates node details', function () {
        $node = Node::factory()->create([
            'name' => 'Old Name',
        ]);

        $response = $this->put(route('nodes.update', $node), [
            'name' => 'New Name',
            'host' => $node->host,
            'user' => $node->user,
            'port' => $node->port,
        ]);

        $response->assertRedirect(route('nodes.index'));
        expect($node->fresh()->name)->toBe('New Name');
    });

    it('allows updating host to localhost', function () {
        Node::factory()->local()->create();
        $node = Node::factory()->create(['host' => '192.168.1.100']);

        $response = $this->put(route('nodes.update', $node), [
            'name' => $node->name,
            'host' => 'localhost',
            'user' => $node->user,
            'port' => $node->port,
        ]);

        $response->assertRedirect(route('nodes.index'));
        expect($node->fresh()->isLocal())->toBeTrue();
    });

    it('validates required fields on update', function () {
        $node = Node::factory()->create();

        $response = $this->put(route('nodes.update', $node), []);

        $response->assertSessionHasErrors(['name', 'host', 'user', 'port']);
    });
});

describe('destroy', function () {
    it('deletes a node', function () {
        $node = Node::factory()->create();
        $id = $node->id;

        $response = $this->delete(route('nodes.destroy', $node));

        $response->assertRedirect(route('nodes.index'));
        expect(Node::find($id))->toBeNull();
    });
});

describe('setDefault', function () {
    it('sets node as default', function () {
        $node1 = Node::factory()->default()->create();
        $node2 = Node::factory()->create();

        $response = $this->post(route('nodes.set-default', $node2));

        $response->assertRedirect(route('nodes.show', $node2));
        expect($node1->fresh()->is_default)->toBeFalse();
        expect($node2->fresh()->is_default)->toBeTrue();
    });
});

describe('testConnection', function () {
    it('returns success for node', function () {
        $node = Node::factory()->create();

        // API route: /api/nodes/{node}/test-connection
        $response = $this->post("/api/nodes/{$node->id}/test-connection");

        $response->assertJson(['success' => true]);
    });
});
