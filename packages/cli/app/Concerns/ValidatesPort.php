<?php

declare(strict_types=1);

namespace App\Concerns;

trait ValidatesPort
{
    protected function validatePort(string $value): ?string
    {
        if (! is_numeric($value)) {
            return 'Port must be a number.';
        }

        $port = (int) $value;

        if ($port < 1 || $port > 65535) {
            return 'Port must be between 1 and 65535.';
        }

        return null;
    }
}
