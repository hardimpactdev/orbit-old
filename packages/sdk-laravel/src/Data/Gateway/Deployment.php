<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Data\Gateway;

use Spatie\LaravelData\Data;

class Deployment extends Data
{
    public function __construct(
        public readonly int $id,
        public readonly string $projectSlug,
        public readonly ?string $projectName,
        public readonly ?string $githubRepo,
        public readonly ?string $domain,
        public readonly ?string $url,
        public readonly ?string $phpVersion,
        public readonly string $status,
        public readonly ?string $errorMessage,
        public readonly array $node,
        public readonly ?string $createdAt = null,
        public readonly ?string $cloudflareRecordId = null,
    ) {}
}
