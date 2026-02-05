<?php

declare(strict_types=1);

namespace App\Services;

/**
 * IP address validation service.
 */
final class IpValidator
{
    /**
     * Validate an IP address.
     *
     * Returns null if valid, or an error message string if invalid.
     * Allows both public and private/reserved IPs.
     */
    public function validate(string $ip): ?string
    {
        if ($ip === '') {
            return 'IP address is required';
        }

        // First check if it's a valid public IP (non-private, non-reserved)
        $isPublic = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;

        // If not public, check if it's a valid private/reserved IP
        if (! $isPublic) {
            $isValidPrivate = filter_var($ip, FILTER_VALIDATE_IP) !== false;
            if (! $isValidPrivate) {
                return 'Invalid IP address format';
            }
        }

        return null;
    }
}
