<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Core\Database\Factories;

use HardImpact\Orbit\Core\Models\GatewayProject;
use Illuminate\Database\Eloquent\Factories\Factory;

class GatewayProjectFactory extends Factory
{
    protected $model = GatewayProject::class;

    public function definition(): array
    {
        return [
            'slug' => $this->faker->unique()->slug(2),
            'name' => $this->faker->words(2, true),
            'github_repo' => null,
            'production_domain' => null,
            'cloudflare_zone_id' => null,
            'cloudflare_zone_name' => null,
            'metadata' => null,
        ];
    }

    public function withProductionDomain(string $domain = 'example.com'): static
    {
        return $this->state(fn (array $attributes) => [
            'production_domain' => $domain,
        ]);
    }

    public function withCloudflareZone(string $zoneId = 'zone_123', string $zoneName = 'example.com'): static
    {
        return $this->state(fn (array $attributes) => [
            'cloudflare_zone_id' => $zoneId,
            'cloudflare_zone_name' => $zoneName,
        ]);
    }
}
