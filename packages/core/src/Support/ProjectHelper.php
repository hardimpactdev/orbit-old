<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Core\Support;

use HardImpact\Orbit\Core\Contracts\ProvisionLoggerContract;
use HardImpact\Orbit\Core\Data\ProvisionContext;
use HardImpact\Orbit\Core\Enums\RepoIntent;
use HardImpact\Orbit\Core\Services\Provision\ProvisionPipeline;

final class ProjectHelper
{
    /**
     * Expand ~ to the user's home directory.
     */
    public static function expandPath(string $path): string
    {
        if (str_starts_with($path, '~/')) {
            $home = $_SERVER['HOME'] ?? config('orbit.home_directory') ?? '/home/orbit';

            return $home . substr($path, 1);
        }

        return $path;
    }

    /**
     * Normalize a GitHub repo URL to owner/repo format.
     */
    public static function normalizeRepoUrl(?string $url): ?string
    {
        if (! $url) {
            return null;
        }

        if (preg_match('/github\.com[:\\/]([^\\/]+\\/[^\\/\\s]+?)(?:\\.git)?$/', $url, $matches)) {
            return $matches[1];
        }

        return str_replace('.git', '', $url);
    }

    /**
     * Detect the project type based on file structure.
     */
    public static function detectProjectType(string $directory): string
    {
        $hasPublicFolder = is_dir("{$directory}/public");
        $hasArtisan = file_exists("{$directory}/artisan");
        $composerJson = "{$directory}/composer.json";

        if (file_exists($composerJson)) {
            $composer = json_decode(file_get_contents($composerJson), true);

            $type = $composer['type'] ?? null;
            if ($type === 'library' || $type === 'laravel-package') {
                return 'laravel-package';
            }

            $extra = $composer['extra'] ?? [];
            if (isset($extra['laravel']['providers']) || isset($extra['laravel']['aliases'])) {
                return 'laravel-package';
            }

            if (isset($composer['require']['laravel-zero/framework'])) {
                return 'cli';
            }
        }

        if ($hasPublicFolder && $hasArtisan) {
            return 'laravel-app';
        }

        if ($hasArtisan) {
            return 'cli';
        }

        if ($hasPublicFolder) {
            return 'web';
        }

        return 'unknown';
    }

    /**
     * Handle repository operations (fork/template creation).
     */
    public static function handleRepositoryOperations(
        ProvisionContext $context,
        RepoIntent $intent,
        ProvisionPipeline $pipeline,
        ProvisionLoggerContract $logger,
    ): ProvisionContext {
        $github = $pipeline->getGitHubService();

        // Fork flow
        if ($intent === RepoIntent::Fork && $context->cloneUrl) {
            $result = $pipeline->forkRepository($context, $logger);
            if ($result->isFailed()) {
                throw new \RuntimeException($result->error ?? 'Fork failed');
            }

            return $context->withRepoInfo(
                $result->data['repo'] ?? null,
                $result->data['cloneUrl'] ?? null
            );
        }

        // Template flow
        if ($intent === RepoIntent::Template && $context->template) {
            $owner = $context->getGitHubOwner($github->getUsername());
            if (! $owner) {
                throw new \RuntimeException('Could not determine GitHub username for template');
            }

            $targetRepo = "{$owner}/{$context->slug}";
            $result = $pipeline->createFromTemplate($context, $logger, $targetRepo);

            if ($result->isFailed()) {
                throw new \RuntimeException($result->error ?? 'Template creation failed');
            }

            return $context->withRepoInfo(
                $result->data['repo'] ?? null,
                $result->data['cloneUrl'] ?? null
            );
        }

        return $context;
    }
}
