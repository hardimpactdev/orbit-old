<?php

declare(strict_types=1);

namespace App\Actions\Prepare\Mac;

use App\Data\Install\InstallContext;
use App\Services\Install\InstallLogger;
use HardImpact\Orbit\Core\Data\StepResult;
use Illuminate\Support\Facades\Process;

final readonly class PreparePhp
{
    public function handle(InstallContext $context, InstallLogger $logger): StepResult
    {
        // Check Homebrew availability
        $brewCheck = Process::run('which brew');
        if ($brewCheck->failed()) {
            return StepResult::failed('Homebrew is not installed. It will be installed during the installation.');
        }
        $logger->success('Homebrew available');

        // Check shivammathur/php tap
        $tapCheck = Process::run('brew tap | grep shivammathur/php');
        if ($tapCheck->successful()) {
            $logger->success('shivammathur/php tap already added');
        } else {
            $logger->step('shivammathur/php tap will be added during installation');
        }

        // Validate requested PHP versions
        $validVersions = ['8.1', '8.2', '8.3', '8.4', '8.5'];
        foreach ($context->phpVersions as $version) {
            if (! in_array($version, $validVersions, true)) {
                return StepResult::failed("PHP version {$version} is not supported. Supported versions: ".implode(', ', $validVersions));
            }
        }
        $logger->success('PHP versions '.implode(', ', $context->phpVersions).' are valid');

        // Check for existing PHP-FPM configuration
        $socketDir = "{$context->homeDir}/.config/orbit/php";
        if (is_dir($socketDir)) {
            $sockets = glob("{$socketDir}/*.sock");
            if ($sockets !== false && count($sockets) > 0) {
                $logger->success('PHP-FPM already configured ('.count($sockets).' socket(s))');

                return StepResult::success();
            }
        }

        $logger->step('PHP-FPM will be configured during installation');

        return StepResult::success();
    }
}
