<?php

declare(strict_types=1);

namespace App\Actions\Install\Shared;

use App\Data\Install\InstallContext;
use App\Services\Install\InstallLogger;
use HardImpact\Orbit\Core\Data\StepResult;
use HardImpact\Orbit\Core\Models\Node;

final readonly class InitializeNode
{
    public function handle(InstallContext $context, InstallLogger $logger): StepResult
    {
        $existing = Node::getSelf();
        if ($existing !== null) {
            $logger->skip("Node already exists: {$existing->getAttribute('name')}");

            return StepResult::success();
        }

        $hostname = gethostname() ?: 'localhost';
        $node = Node::create([
            'name' => $hostname,
            'host' => '127.0.0.1',
            'is_default' => true,
            'node_type' => $context->nodeType,
            'metadata' => [
                'platform' => PHP_OS_FAMILY,
                'php_versions' => $context->phpVersions,
            ],
        ]);

        $logger->success("Node created: {$node->getAttribute('name')}");

        return StepResult::success();
    }
}
