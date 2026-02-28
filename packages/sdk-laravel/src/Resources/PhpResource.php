<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Resources;

use HardImpact\Orbit\Data\PhpVersion;
use HardImpact\Orbit\Requests\Php\GetPhpVersions;
use HardImpact\Orbit\Requests\Php\ResetPhpVersion;
use HardImpact\Orbit\Requests\Php\SetPhpVersion;
use Saloon\Http\BaseResource;

class PhpResource extends BaseResource
{
    public function versions(): PhpVersion
    {
        return PhpVersion::from($this->connector->send(new GetPhpVersions)->json());
    }

    /**
     * @return array<string, mixed>
     */
    public function setVersion(string $project, string $version): array
    {
        return $this->connector->send(new SetPhpVersion($project, $version))->json();
    }

    /**
     * @return array<string, mixed>
     */
    public function resetVersion(string $project): array
    {
        return $this->connector->send(new ResetPhpVersion($project))->json();
    }
}
