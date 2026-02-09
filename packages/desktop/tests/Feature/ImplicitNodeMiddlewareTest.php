<?php

use HardImpact\Orbit\Core\Models\Node;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Config::set('orbit.multi_node', false);

    Route::middleware([\HardImpact\Orbit\App\Http\Middleware\ImplicitNode::class])
        ->get('/test-middleware/{node?}', function (Node $node) {
            return response()->json(['id' => $node->id]);
        });
});

test('it injects default node when multi_node is false', function () {
    $node = createNode(['is_default' => true, 'host' => 'localhost']);

    $response = $this->get('/test-middleware');

    $response->assertStatus(200);
    $response->assertJson(['id' => $node->id]);
});

test('it does not inject when multi_node is true', function () {
    Config::set('orbit.multi_node', true);
    createNode(['is_default' => true, 'host' => 'localhost']);

    $response = $this->getJson('/test-middleware');

    $response->assertJson(['id' => null]);
});

test('it aborts 500 when no node exists and multi_node is false', function () {
    $response = $this->getJson('/test-middleware');

    $response->assertStatus(500);
    $response->assertJson(['message' => 'No node found. Run: php artisan orbit:init']);
});

test('it warns if multiple default nodes exist', function () {
    Log::shouldReceive('warning')
        ->once()
        ->with('Multiple is_default=true nodes found (2). Using first.');

    createNode(['is_default' => true, 'host' => 'localhost', 'name' => 'Node 1']);
    createNode(['is_default' => true, 'host' => 'localhost', 'name' => 'Node 2']);

    $response = $this->get('/test-middleware');

    $response->assertStatus(200);
});
