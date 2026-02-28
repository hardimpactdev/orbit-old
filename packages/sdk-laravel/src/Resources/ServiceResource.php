<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Resources;

use HardImpact\Orbit\Requests\Services\DisableService;
use HardImpact\Orbit\Requests\Services\EnableService;
use HardImpact\Orbit\Requests\Services\RestartAllServices;
use HardImpact\Orbit\Requests\Services\RestartService;
use HardImpact\Orbit\Requests\Services\StartAllServices;
use HardImpact\Orbit\Requests\Services\StartService;
use HardImpact\Orbit\Requests\Services\StopAllServices;
use HardImpact\Orbit\Requests\Services\StopService;
use Saloon\Http\BaseResource;

class ServiceResource extends BaseResource
{
    /**
     * @return array<string, mixed>
     */
    public function startAll(?string $project = null): array
    {
        return $this->connector->send(new StartAllServices($project))->json();
    }

    /**
     * @return array<string, mixed>
     */
    public function stopAll(): array
    {
        return $this->connector->send(new StopAllServices)->json();
    }

    /**
     * @return array<string, mixed>
     */
    public function restartAll(): array
    {
        return $this->connector->send(new RestartAllServices)->json();
    }

    /**
     * @return array<string, mixed>
     */
    public function start(string $service): array
    {
        return $this->connector->send(new StartService($service))->json();
    }

    /**
     * @return array<string, mixed>
     */
    public function stop(string $service): array
    {
        return $this->connector->send(new StopService($service))->json();
    }

    /**
     * @return array<string, mixed>
     */
    public function restart(string $service): array
    {
        return $this->connector->send(new RestartService($service))->json();
    }

    /**
     * @param  array<string, mixed>|null  $options
     * @return array<string, mixed>
     */
    public function enable(string $service, ?array $options = null): array
    {
        return $this->connector->send(new EnableService($service, $options))->json();
    }

    /**
     * @return array<string, mixed>
     */
    public function disable(string $service): array
    {
        return $this->connector->send(new DisableService($service))->json();
    }
}
