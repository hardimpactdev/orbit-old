<?php

declare(strict_types=1);

namespace App\Actions\Install\Shared;

use App\Data\Install\InstallContext;
use App\Services\Install\InstallLogger;
use HardImpact\Orbit\Core\Data\StepResult;
use HardImpact\Orbit\Core\Services\SettingEncryptor;

final readonly class CreateDirectories
{
    private const DIRECTORIES = [
        '',
        'php',
        'caddy',
        'dns',
        'postgres',
        'postgres/data',
        'redis',
        'redis/data',
        'mailpit',
        'reverb',
        'logs',
        'logs/provision',
    ];

    public function handle(InstallContext $context, InstallLogger $logger): StepResult
    {
        foreach (self::DIRECTORIES as $dir) {
            $path = $dir === '' ? $context->configDir : "{$context->configDir}/{$dir}";

            if (is_dir($path)) {
                continue;
            }

            if (! mkdir($path, 0755, true) && ! is_dir($path)) {
                return StepResult::failed("Failed to create directory: {$path}");
            }
        }

        // Also create projects directory
        $projectsPath = "{$context->homeDir}/projects";
        if (! is_dir($projectsPath)) {
            if (! mkdir($projectsPath, 0755, true) && ! is_dir($projectsPath)) {
                return StepResult::failed("Failed to create directory: {$projectsPath}");
            }
        }

        $logger->success('Directory structure created');

        // Generate encryption key for sensitive settings if not present
        $keyPath = $context->configDir.'/encryption.key';
        if (! file_exists($keyPath)) {
            SettingEncryptor::getInstance()->generateKeyFile();
            $logger->success('Encryption key generated');
        }

        return StepResult::success();
    }
}
