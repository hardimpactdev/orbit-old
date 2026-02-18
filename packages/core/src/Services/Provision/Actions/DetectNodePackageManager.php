<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Core\Services\Provision\Actions;

use HardImpact\Orbit\Core\Contracts\ProvisionLoggerContract;
use HardImpact\Orbit\Core\Data\ProvisionContext;
use HardImpact\Orbit\Core\Data\StepResult;

final readonly class DetectNodePackageManager
{
    public function handle(ProvisionContext $context, ProvisionLoggerContract $logger): StepResult
    {
        $projectPath = $context->projectPath;
        $packageJsonPath = "{$projectPath}/package.json";

        if (! file_exists($packageJsonPath)) {
            $logger->info('No package.json found, skipping Node package manager detection');

            return StepResult::success(['packageManager' => null]);
        }

        // Check for conflicting lock files
        $lockFiles = [];
        if (file_exists("{$projectPath}/bun.lock") || file_exists("{$projectPath}/bun.lockb")) {
            $lockFiles[] = 'bun.lock';
        }
        if (file_exists("{$projectPath}/package-lock.json")) {
            $lockFiles[] = 'package-lock.json';
        }

        if (count($lockFiles) > 1) {
            return StepResult::failed('Multiple lock files detected: '.implode(', ', $lockFiles).'. Please remove one.');
        }

        // Detect package manager from lock file
        $preferredManager = match (true) {
            file_exists("{$projectPath}/bun.lock") || file_exists("{$projectPath}/bun.lockb") => 'bun',
            file_exists("{$projectPath}/package-lock.json") => 'npm',
            default => 'npm',
        };

        // Verify the detected package manager is available
        $packageManager = $this->ensurePackageManagerAvailable($preferredManager, $logger);

        if (! $packageManager) {
            return StepResult::failed("No Node package manager available. Install {$preferredManager} or configure an alternative.");
        }

        $logger->info("Using Node package manager: {$packageManager}");

        return StepResult::success(['packageManager' => $packageManager]);
    }

    /**
     * Verify package manager is available, fall back to alternatives if not.
     */
    private function ensurePackageManagerAvailable(string $preferred, ProvisionLoggerContract $logger): ?string
    {
        // Check if preferred manager is available
        if ($this->isCommandAvailable($preferred)) {
            return $preferred;
        }

        // Fallback order: bun → npm → yarn → pnpm
        $fallbacks = match ($preferred) {
            'npm' => ['bun', 'yarn', 'pnpm'],
            'bun' => ['npm', 'yarn', 'pnpm'],
            default => ['npm', 'bun', 'yarn', 'pnpm'],
        };

        foreach ($fallbacks as $fallback) {
            if ($this->isCommandAvailable($fallback)) {
                $logger->warn("{$preferred} not found, falling back to {$fallback}");

                return $fallback;
            }
        }

        return null;
    }

    private function isCommandAvailable(string $command): bool
    {
        $result = shell_exec("command -v {$command} 2>/dev/null");

        return ! empty(trim($result ?? ''));
    }
}
