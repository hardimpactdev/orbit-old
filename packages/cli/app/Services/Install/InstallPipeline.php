<?php

declare(strict_types=1);

namespace App\Services\Install;

use App\Contracts\Template;
use App\Data\Install\InstallContext;
use HardImpact\Orbit\Core\Data\StepResult;

final readonly class InstallPipeline
{
    public function run(Template $template, string $osFamily, InstallContext $context, InstallLogger $logger): StepResult
    {
        // Phase 1: Preparation (read-only validation)
        $prepareSteps = $template->prepareSteps($osFamily);

        if (count($prepareSteps) > 0) {
            $logger->newLine();
            $logger->info('Checking prerequisites...');
            $logger->newLine();

            foreach ($prepareSteps as $step) {
                $result = $logger->spinner(
                    $step['name'],
                    fn () => app($step['action'])->handle($context, $logger)
                );

                if ($result->isFailed()) {
                    return $result;
                }
            }

            $logger->newLine();
            $logger->success('All prerequisites verified');
            $logger->newLine();
        }

        // Phase 2: Installation
        $steps = $template->installSteps($osFamily);

        $logger->newLine();
        $logger->info('Installing components...');
        $logger->newLine();

        foreach ($steps as $step) {
            $result = $logger->spinner(
                $step['name'],
                fn () => app($step['action'])->handle($context, $logger)
            );

            if ($result->isFailed()) {
                return $result;
            }
        }

        return StepResult::success();
    }
}
