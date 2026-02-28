<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Resources;

use HardImpact\Orbit\Data\TrackedJob;
use HardImpact\Orbit\Requests\Jobs\GetJob;
use Saloon\Http\BaseResource;

class JobResource extends BaseResource
{
    public function get(string $id): TrackedJob
    {
        return TrackedJob::from(
            $this->connector->send(new GetJob($id))->json()
        );
    }
}
