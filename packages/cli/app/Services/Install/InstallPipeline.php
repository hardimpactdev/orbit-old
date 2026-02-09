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
        $prepareSteps = $template->prepareSteps($osFamily, $context);

        if (count($prepareSteps) > 0) {
            foreach ($prepareSteps as $step) {
                $result = $logger->spinner(
                    $step['name'],
                    fn () => app($step['action'])->handle($context, $logger)
                );

                if ($result->isFailed()) {
                    return $result;
                }
            }
        }

        // Phase 2: Installation
        $steps = $template->installSteps($osFamily, $context);

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
