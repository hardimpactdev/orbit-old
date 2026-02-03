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
        $steps = $template->installSteps($osFamily);
        $total = count($steps);

        foreach ($steps as $index => $step) {
            $logger->progress($index + 1, $total, $step['name']);

            $result = app($step['action'])->handle($context, $logger);

            if ($result->isFailed()) {
                return $result;
            }
        }

        return StepResult::success();
    }
}
