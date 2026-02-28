<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Resources;

use HardImpact\Orbit\Requests\Config\GetConfig;
use Saloon\Http\BaseResource;

class ConfigResource extends BaseResource
{
    /**
     * @return array<string, mixed>
     */
    public function get(): array
    {
        return $this->connector->send(new GetConfig)->json();
    }
}
