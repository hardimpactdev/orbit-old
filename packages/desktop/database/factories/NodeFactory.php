<?php

namespace Database\Factories;

use HardImpact\Orbit\Core\Enums\NodeStatus;
use HardImpact\Orbit\Core\Models\Node;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\HardImpact\Orbit\Core\Models\Node>
 */
class NodeFactory extends Factory
{
    protected $model = Node::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->word(),
            'host' => 'localhost',
            'user' => 'orbit',
            'port' => 22,
            'is_default' => false,
            'status' => NodeStatus::Active,
        ];
    }

    public function local(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Local',
            'is_default' => true,
        ]);
    }
}
