<?php

declare(strict_types=1);

namespace Tests\Feature;

use HardImpact\Orbit\Core\Models\Node;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrbitInitCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_local_node(): void
    {
        $this->assertDatabaseCount('nodes', 0);

        $this->artisan('orbit:init', ['--name' => 'Local'])
            ->assertExitCode(0);

        $this->assertDatabaseCount('nodes', 1);
        $this->assertDatabaseHas('nodes', [
            'name' => 'Local',
            'is_default' => true,
        ]);
    }

    public function test_idempotent_does_not_create_duplicate(): void
    {
        // Run twice
        $this->artisan('orbit:init', ['--name' => 'Local'])->assertExitCode(0);
        $this->artisan('orbit:init', ['--name' => 'Local'])->assertExitCode(0);

        // Still only one node
        $this->assertDatabaseCount('nodes', 1);
    }

    public function test_skips_when_local_node_exists(): void
    {
        createNode(['is_default' => true, 'host' => 'localhost']);

        $this->artisan('orbit:init', ['--name' => 'Local'])
            ->expectsOutput('Node already exists. Skipping.')
            ->assertExitCode(0);
    }

    public function test_creates_with_correct_defaults(): void
    {
        $configPath = rtrim(getenv('HOME'), '/').'/.config/orbit/config.json';

        // Mock File facade to return default TLD
        \Illuminate\Support\Facades\File::shouldReceive('exists')
            ->with($configPath)
            ->andReturn(false);

        $this->artisan('orbit:init', ['--name' => 'Local'])->assertExitCode(0);

        $node = Node::where('is_default', true)->first();

        $this->assertEquals('Local', $node->name);
        $this->assertEquals('localhost', $node->host);
        $this->assertTrue($node->is_default);
        $this->assertEquals('test', $node->tld);
    }

    public function test_reads_tld_from_config(): void
    {
        $configPath = rtrim(getenv('HOME'), '/').'/.config/orbit/config.json';

        \Illuminate\Support\Facades\File::shouldReceive('exists')
            ->with($configPath)
            ->andReturn(true);

        \Illuminate\Support\Facades\File::shouldReceive('get')
            ->with($configPath)
            ->andReturn(json_encode(['tld' => 'orbit']));

        $this->artisan('orbit:init', ['--name' => 'Local'])
            ->expectsOutputToContain("Node 'Local' initialized")
            ->assertExitCode(0);

        $this->assertDatabaseHas('nodes', [
            'is_default' => true,
            'tld' => 'orbit',
        ]);
    }
}
