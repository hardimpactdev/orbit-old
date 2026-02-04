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

        // Check port 9000 availability
        $portCheck = Process::run('lsof -i :9000 2> /dev/null');
        if ($portCheck->successful()) {
            return StepResult::failed('Port 9000 is already in use. This may conflict with PHP-FPM.');
        }
        $logger->success('Port 9000 available');

        // Check for existing Orbit FPM socket conflicts
        $socketDir = "{$context->homeDir}/.config/orbit/php";
        if (is_dir($socketDir)) {
            $sockets = glob("{$socketDir}/*.sock");
            if ($sockets !== false && count($sockets) > 0) {
                $logger->warn('Existing PHP-FPM sockets found (will be overwritten)');
            }
        }

        return StepResult::success();
    }
}
