<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Data\Gateway;

use Spatie\LaravelData\Data;

class DnsMapping extends Data
{
    public function __construct(
        public readonly string $tld,
        public readonly string $ip,
    ) {}
}
