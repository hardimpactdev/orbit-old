<?php

declare(strict_types=1);

namespace App\Actions\Install\Brew;

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

        if ($context->needsNode() && ! $this->platformService->commandExists('node')) {
            $logger->step('Installing Node...');
            $result = Process::timeout(300)->run('brew install node');
            if (! $result->successful()) {
                $logger->warn('Failed to install Node - you may need to install it manually');
            } else {
                $logger->success('Node installed');
            }
        }

        if (in_array('bun', $context->nodePackageManagers, true) && ! $this->platformService->commandExists('bun')) {
            $logger->step('Installing Bun...');
            $result = Process::timeout(300)->run('brew install oven-sh/bun/bun');
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
