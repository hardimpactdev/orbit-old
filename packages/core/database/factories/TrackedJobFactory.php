<?php

namespace HardImpact\Orbit\Core\Database\Factories;

use HardImpact\Orbit\Core\Enums\TrackedJobStatus;
use HardImpact\Orbit\Core\Models\TrackedJob;
use Illuminate\Database\Eloquent\Factories\Factory;

class TrackedJobFactory extends Factory
{
    protected $model = TrackedJob::class;

    public function definition(): array
    {
        return [
            'name' => 'create-site:'.$this->faker->slug(),
            'status' => TrackedJobStatus::Pending,
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => TrackedJobStatus::Pending,
        ]);
    }

    public function processing(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => TrackedJobStatus::Processing,
            'started_at' => now(),
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => TrackedJobStatus::Completed,
            'started_at' => now()->subMinutes(5),
            'finished_at' => now(),
            'output' => json_encode(['success' => true]),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => TrackedJobStatus::Failed,
            'started_at' => now()->subMinutes(5),
            'finished_at' => now(),
            'output' => 'Command failed',
        ]);
    }
}
