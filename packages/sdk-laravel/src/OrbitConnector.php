<?php

declare(strict_types=1);

namespace HardImpact\Orbit;

use Saloon\Http\Connector;
use Saloon\Traits\Plugins\AcceptsJson;

class OrbitConnector extends Connector
{
    use AcceptsJson;

    public function __construct(
        protected string $baseUrl,
        protected int $timeout = 30,
        protected bool $verifySsl = false,
    ) {}

    public function resolveBaseUrl(): string
    {
        return rtrim($this->baseUrl, '/').'/api';
    }

    /** @return array<string, string> */
    protected function defaultHeaders(): array
    {
        return [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];
    }

    /** @return array<string, mixed> */
    protected function defaultConfig(): array
    {
        return [
            'timeout' => $this->timeout,
            'verify' => $this->verifySsl,
        ];
    }
}
