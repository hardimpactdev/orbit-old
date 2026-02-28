<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Data\Gateway;

use Spatie\LaravelData\Data;

class CloudflareRecord extends Data
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $type,
        public readonly string $content,
        public readonly bool $proxied,
        public readonly ?int $ttl = null,
    ) {}
}
