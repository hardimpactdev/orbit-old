<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Data;

use Spatie\LaravelData\Data;

/**
 * Response from GET /api/php-versions
 *
 * Represents the PHP version support metadata returned by the orbit node.
 */
class PhpVersion extends Data
{
    /**
     * @param  array<int, string>  $versions  List of supported PHP version strings (e.g. ['8.5', '8.4', '8.3'])
     */
    public function __construct(
        public readonly array $versions,
    ) {}
}
