<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Core\Support;

final class PhpVersion
{
    /**
     * All PHP versions supported by orbit, newest first.
     */
    public const SUPPORTED = ['8.5', '8.4', '8.3'];

    /**
     * The default PHP version for new projects.
     */
    public const DEFAULT = '8.4';

    /**
     * Check if a version string is a supported PHP version.
     */
    public static function isValid(string $version): bool
    {
        return in_array($version, self::SUPPORTED, true);
    }

    /**
     * Get the recommended PHP version for a composer.json constraint.
     * Returns the highest supported version that satisfies the constraint.
     */
    public static function recommendedForConstraint(string $constraint): string
    {
        foreach (self::SUPPORTED as $version) {
            if (self::satisfiesConstraint($version, $constraint)) {
                return $version;
            }
        }

        return self::DEFAULT;
    }

    private static function satisfiesConstraint(string $version, string $constraint): bool
    {
        $constraint = str_replace(' ', '', $constraint);

        // Handle OR constraints (e.g., "^8.2|^8.3")
        if (str_contains($constraint, '|') || str_contains($constraint, '||')) {
            $parts = preg_split('/\|\|?/', $constraint);
            foreach ($parts as $part) {
                if (self::satisfiesConstraint($version, trim($part))) {
                    return true;
                }
            }

            return false;
        }

        // Handle caret constraint (e.g., "^8.2")
        if (str_starts_with($constraint, '^')) {
            $min = substr($constraint, 1);
            $major = explode('.', $min)[0];

            return version_compare($version, $min, '>=')
                && version_compare($version, ($major + 1) . '.0', '<');
        }

        // Handle tilde constraint (e.g., "~8.2")
        if (str_starts_with($constraint, '~')) {
            $min = substr($constraint, 1);

            return version_compare($version, $min, '>=')
                && version_compare($version, (explode('.', $min)[0]) . '.' . ((int) explode('.', $min)[1] + 1), '<');
        }

        // Handle >= constraint
        if (str_starts_with($constraint, '>=')) {
            return version_compare($version, substr($constraint, 2), '>=');
        }

        return false;
    }
}
