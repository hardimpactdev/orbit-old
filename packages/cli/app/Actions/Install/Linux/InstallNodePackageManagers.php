<?php

declare(strict_types=1);

namespace App\Actions\Install\Linux;

use App\Data\Install\InstallContext;
use App\Services\Install\InstallLogger;
use App\Services\PlatformService;
use HardImpact\Orbit\Core\Data\StepResult;
use Illuminate\Support\Facades\Process;

final readonly class InstallNodePackageManagers
{
    public function __construct(
        private PlatformService $platformService,
    ) {}

    public function handle(InstallContext $context, InstallLogger $logger): StepResult
    {
        if ($context->nodePackageManagers === []) {
            $logger->skip('No package managers selected');

            return StepResult::success();
        }

        // Install prerequisites for Bun and nvm
        $prerequisites = [];
        if (! $this->platformService->commandExists('unzip')) {
            $prerequisites[] = 'unzip';
        }
        if (! $this->platformService->commandExists('curl')) {
            $prerequisites[] = 'curl';
        }

        if ($prerequisites !== []) {
            $logger->step('Installing prerequisites ('.implode(', ', $prerequisites).')...');
            $packages = implode(' ', $prerequisites);
            $result = Process::timeout(120)->run("sudo apt-get update && sudo apt-get install -y {$packages}");
            if (! $result->successful()) {
                $logger->warn('Failed to install prerequisites - installations may fail');
            }
        }

        if ($context->needsNode() && ! $this->platformService->commandExists('node')) {
            $logger->step('Installing Node via nvm...');

            // Install nvm first
            $nvmInstall = Process::timeout(300)->run(
                'curl -o- https://raw.githubusercontent.com/nvm-sh/nvm/v0.40.1/install.sh | bash'
            );

            if (! $nvmInstall->successful()) {
                $logger->warn('Failed to install nvm - you may need to install Node manually');
            } else {
                // Install latest LTS node
                $nodeInstall = Process::timeout(300)->run(
                    'export NVM_DIR="$HOME/.nvm" && [ -s "$NVM_DIR/nvm.sh" ] && . "$NVM_DIR/nvm.sh" && nvm install --lts'
                );

                if (! $nodeInstall->successful()) {
                    $logger->warn('Failed to install Node - you may need to install it manually');
                } else {
                    $logger->success('Node installed via nvm');
                }
            }
        }

        if (in_array('bun', $context->nodePackageManagers, true) && ! $this->platformService->commandExists('bun')) {
            $logger->step('Installing Bun...');
            $result = Process::timeout(300)->run('curl -fsSL https://bun.sh/install | bash');
            if (! $result->successful()) {
                $logger->warn('Failed to install Bun - you may need to install it manually');
            } else {
                $logger->success('Bun installed');
            }
        }

        if (in_array('yarn', $context->nodePackageManagers, true) && ! $this->platformService->commandExists('yarn')) {
            $logger->step('Installing Yarn...');
            $result = Process::timeout(120)->run('npm install -g yarn');
            if (! $result->successful()) {
                $logger->warn('Failed to install Yarn - you may need to install it manually');
            } else {
                $logger->success('Yarn installed');
            }
        }

        if (in_array('pnpm', $context->nodePackageManagers, true) && ! $this->platformService->commandExists('pnpm')) {
            $logger->step('Installing pnpm...');
            $result = Process::timeout(120)->run('npm install -g pnpm');
            if (! $result->successful()) {
                $logger->warn('Failed to install pnpm - you may need to install it manually');
            } else {
                $logger->success('pnpm installed');
            }
        }

        return StepResult::success();
    }
}
