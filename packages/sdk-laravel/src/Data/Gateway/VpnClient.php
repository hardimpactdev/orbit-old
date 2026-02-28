<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Data\Gateway;

use Spatie\LaravelData\Data;

class VpnClient extends Data
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $ip,
        public readonly bool $online,
        public readonly ?string $tld,
        public readonly ?string $latestHandshakeAt,
        public readonly ?string $publicKey,
        public readonly bool $enabled,
    ) {}
}
