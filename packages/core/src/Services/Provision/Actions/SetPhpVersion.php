<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Core\Services\Provision\Actions;

use HardImpact\Orbit\Core\Contracts\ProvisionLoggerContract;
use HardImpact\Orbit\Core\Data\ProvisionContext;
use HardImpact\Orbit\Core\Data\StepResult;
use HardImpact\Orbit\Core\Models\Project;
use HardImpact\Orbit\Core\Support\PhpVersion;

final readonly class SetPhpVersion
{

    public function handle(ProvisionContext $context, ProvisionLoggerContract $logger): StepResult
    {
        // Determine PHP version
        $version = $context->phpVersion;

        if (! $version || ! PhpVersion::isValid($version)) {
            $version = $this->detectPhpVersionFromComposer($context->projectPath, $logger);
        }

        $logger->info("Setting PHP version to {$version}");

        // Write .php-version file
        $versionFile = "{$context->projectPath}/.php-version";
        file_put_contents($versionFile, "{$version}\n");
        $logger->log('Wrote .php-version file');

        // Update database if project ID is available
        if ($context->projectId) {
            $project = Project::find($context->projectId);
            if ($project) {
                $project->update(['php_version' => $version]);
                $logger->log('Updated database with PHP version');
            }
        }

        return StepResult::success(['phpVersion' => $version]);
    }

    private function detectPhpVersionFromComposer(string $projectPath, ProvisionLoggerContract $logger): string
    {
        $composerPath = "{$projectPath}/composer.json";

        if (! file_exists($composerPath)) {
            $logger->log('No composer.json, using default PHP '.PhpVersion::DEFAULT);

            return PhpVersion::DEFAULT;
        }

        $content = file_get_contents($composerPath);
        if (! $content) {
            return PhpVersion::DEFAULT;
        }

        $composer = json_decode($content, true);
        $constraint = $composer['require']['php'] ?? null;

        if (! $constraint) {
            $logger->log('No PHP constraint in composer.json, using '.PhpVersion::DEFAULT);

            return PhpVersion::DEFAULT;
        }

        $version = $this->getRecommendedPhpVersion($constraint);
        $logger->log("Detected PHP version {$version} from constraint: {$constraint}");

        return $version;
    }

    private function getRecommendedPhpVersion(string $constraint): string
    {
        return PhpVersion::recommendedForConstraint($constraint);
    }
}
