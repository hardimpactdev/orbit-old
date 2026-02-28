<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Resources;

use HardImpact\Orbit\Data\InstanceInfo;
use HardImpact\Orbit\Data\Status;
use HardImpact\Orbit\Requests\Status\GetInstanceInfo;
use HardImpact\Orbit\Requests\Status\GetStatus;
use Saloon\Http\BaseResource;

class StatusResource extends BaseResource
{
    public function get(): Status
    {
        return Status::from($this->connector->send(new GetStatus)->json());
    }

    public function instanceInfo(): InstanceInfo
    {
        return InstanceInfo::from($this->connector->send(new GetInstanceInfo)->json());
    }
}
