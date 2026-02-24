<?php

namespace HardImpact\Orbit\Core\Database\Factories;

use HardImpact\Orbit\Core\Models\Node;
use HardImpact\Orbit\Core\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

class WorkspaceFactory extends Factory
{
    protected $model = Workspace::class;

    public function definition(): array
    {
        $name = $this->faker->words(2, true);

        return [
            'node_id' => Node::factory(),
            'name' => $name,
            'path' => '/home/orbit/projects/'.str_replace(' ', '-', $name),
            'projects' => [],
        ];
    }
}
