<?php

namespace HardImpact\Orbit\Core\Database\Factories;

use HardImpact\Orbit\Core\Enums\NodeEnvironment;
use HardImpact\Orbit\Core\Enums\NodeStatus;
use HardImpact\Orbit\Core\Enums\NodeType;
use HardImpact\Orbit\Core\Models\Node;
use Illuminate\Database\Eloquent\Factories\Factory;

class NodeFactory extends Factory
{
    protected $model = Node::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->words(2, true),
            'host' => $this->faker->ipv4(),
            'user' => $this->faker->userName(),
            'port' => 22,
            'is_default' => false,
            'is_active' => true,
            'status' => NodeStatus::Active,
            'node_type' => NodeType::Local,
            'environment' => NodeEnvironment::Development,
        ];
    }

    public function local(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Local',
            'host' => '127.0.0.1',
            'user' => 'orbit',
            'is_default' => true,
        ]);
    }

    public function default(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_default' => true,
        ]);
    }

    public function provisioning(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => NodeStatus::Provisioning,
        ]);
    }

    public function withError(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => NodeStatus::Error,
            'provisioning_error' => 'Test error message',
        ]);
    }

    public function gateway(): static
    {
        return $this->state(fn (array $attributes) => [
            'node_type' => NodeType::Gateway,
        ]);
    }

    public function client(): static
    {
        return $this->state(fn (array $attributes) => [
            'node_type' => NodeType::Client,
        ]);
    }

    public function production(): static
    {
        return $this->state(fn (array $attributes) => [
            'environment' => NodeEnvironment::Production,
        ]);
    }

    public function staging(): static
    {
        return $this->state(fn (array $attributes) => [
            'environment' => NodeEnvironment::Staging,
        ]);
    }

    public function development(): static
    {
        return $this->state(fn (array $attributes) => [
            'environment' => NodeEnvironment::Development,
        ]);
    }
}
