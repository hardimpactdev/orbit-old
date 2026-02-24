<?php

namespace HardImpact\Orbit\Core\Database\Factories;

use HardImpact\Orbit\Core\Enums\DeploymentStatus;
use HardImpact\Orbit\Core\Models\Deployment;
use HardImpact\Orbit\Core\Models\Node;
use Illuminate\Database\Eloquent\Factories\Factory;

class DeploymentFactory extends Factory
{
    protected $model = Deployment::class;

    public function definition(): array
    {
        $slug = $this->faker->slug(2);

        return [
            'node_id' => Node::factory(),
            'gateway_project_id' => null,
            'project_slug' => $slug,
            'project_name' => str_replace('-', ' ', ucfirst($slug)),
            'github_repo' => $this->faker->userName().'/'.$slug,
            'domain' => $slug.'.'.$this->faker->tld(),
            'url' => 'https://'.$slug.'.'.$this->faker->tld(),
            'php_version' => $this->faker->randomElement(['8.3', '8.4', '8.5']),
            'status' => DeploymentStatus::Active,
            'error_message' => null,
            'cloudflare_record_id' => null,
            'metadata' => null,
        ];
    }

    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => DeploymentStatus::Active,
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => DeploymentStatus::Failed,
            'error_message' => 'Deployment failed: '.$this->faker->sentence(),
        ]);
    }

    public function removed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => DeploymentStatus::Removed,
        ]);
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => DeploymentStatus::Pending,
        ]);
    }
}
